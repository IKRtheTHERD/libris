<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/library.php';

/**
 * SQLite does not implement MySQL's row-lock clause or CURDATE/DATE_ADD syntax.
 * Strip/translate only those statements so the domain workflow can run in an
 * isolated fixture. This adapter does not exercise MySQL locking semantics.
 */
final class LibraryWorkflowSqlitePdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->translateSql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = $this->translateSql($query);

        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    private function translateSql(string $query): string
    {
        $query = preg_replace('/\s+FOR UPDATE\b/i', '', $query) ?? $query;
        $query = preg_replace(
            "/DATE_ADD\\(CURDATE\\(\\), INTERVAL ([0-9]+) DAY\\)/i",
            "date('now', 'localtime', '+$1 day')",
            $query
        ) ?? $query;

        return str_ireplace('CURDATE()', "date('now', 'localtime')", $query);
    }
}

function create_library_workflow_fixture(): LibraryWorkflowSqlitePdo
{
    $pdo = new LibraryWorkflowSqlitePdo();
    $pdo->exec(
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            is_active INTEGER NOT NULL DEFAULT 1
        )'
    );
    $pdo->exec(
        'CREATE TABLE books (
            id INTEGER PRIMARY KEY,
            is_active INTEGER NOT NULL DEFAULT 1
        )'
    );
    $pdo->exec(
        'CREATE TABLE book_copies (
            id INTEGER PRIMARY KEY,
            book_id INTEGER NOT NULL,
            purpose TEXT NOT NULL,
            status TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE loans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            copy_id INTEGER NOT NULL,
            borrowed_at TEXT NOT NULL,
            due_at TEXT NOT NULL,
            returned_at TEXT,
            status TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            book_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            queue_position INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ready_until TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER,
            action TEXT NOT NULL,
            entity_type TEXT,
            entity_id INTEGER,
            details TEXT,
            ip_address BLOB
        )'
    );
    $pdo->exec("INSERT INTO app_settings (setting_key, setting_value) VALUES ('loan_days', '14'), ('max_active_loans', '3')");

    return $pdo;
}

function check_library_borrow(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = create_library_workflow_fixture();
$pdo->exec("INSERT INTO users (id) VALUES (1)");
$pdo->exec("INSERT INTO books (id) VALUES (10)");
$pdo->exec("INSERT INTO book_copies (id, book_id, purpose, status) VALUES (100, 10, 'loan', 'available')");

$borrow = request_book_loan($pdo, 1, 10, 'borrow');
$expectedDueDate = (new DateTimeImmutable('today'))->modify('+14 days')->format('Y-m-d');
check_library_borrow(($borrow['status'] ?? null) === 'borrowed', 'A valid borrow action should return a successful borrow result.');
check_library_borrow(($borrow['due_at'] ?? null) === $expectedDueDate, 'The default loan period should set the due date 14 days ahead.');
check_library_borrow(
    $pdo->query('SELECT status FROM book_copies WHERE id = 100')->fetchColumn() === 'loaned',
    'Borrowing should move the selected physical copy from available to loaned.'
);
check_library_borrow(
    (int) $pdo->query('SELECT COUNT(*) FROM loans WHERE user_id = 1 AND status = "active"')->fetchColumn() === 1,
    'Borrowing should persist one active loan for the member.'
);

$limited = create_library_workflow_fixture();
$limited->exec("INSERT INTO users (id) VALUES (2)");
$limited->exec("INSERT INTO books (id) VALUES (20)");
$limited->exec("INSERT INTO book_copies (id, book_id, purpose, status) VALUES (201, 20, 'loan', 'loaned'), (202, 20, 'loan', 'loaned'), (203, 20, 'loan', 'loaned'), (204, 20, 'loan', 'available')");
$limited->exec(
    "INSERT INTO loans (user_id, copy_id, borrowed_at, due_at, status) VALUES
    (2, 201, '2026-10-01', '2026-10-15', 'active'),
    (2, 202, '2026-10-01', '2026-10-15', 'active'),
    (2, 203, '2026-10-01', '2026-10-15', 'active')"
);
$limitRejected = false;
try {
    request_book_loan($limited, 2, 20, 'borrow');
} catch (DomainException $exception) {
    $limitRejected = $exception->getMessage() === tr('error_active_loan_limit');
}
check_library_borrow($limitRejected, 'A fourth simultaneous loan should be rejected at the configured three-loan limit.');
check_library_borrow(
    (int) $limited->query('SELECT COUNT(*) FROM loans WHERE user_id = 2 AND status = "active"')->fetchColumn() === 3,
    'Rejecting a fourth loan should leave the member with exactly three active loans.'
);
check_library_borrow(
    $limited->query('SELECT status FROM book_copies WHERE id = 204')->fetchColumn() === 'available',
    'Rejecting a fourth loan should leave the next available copy untouched.'
);

$priority = create_library_workflow_fixture();
$priority->exec("INSERT INTO users (id) VALUES (3)");
$priority->exec("INSERT INTO books (id) VALUES (30)");
$priority->exec("INSERT INTO book_copies (id, book_id, purpose, status) VALUES (301, 30, 'loan', 'available'), (302, 30, 'loan', 'reserved')");
$priority->exec(
    "INSERT INTO reservations (id, user_id, book_id, status, queue_position, ready_until)
    VALUES (401, 3, 30, 'ready', NULL, date('now', 'localtime', '+2 days'))"
);

$priorityBorrow = request_book_loan($priority, 3, 30, 'borrow');
check_library_borrow(($priorityBorrow['status'] ?? null) === 'borrowed', 'A member with a ready hold should be able to borrow their reserved copy.');
check_library_borrow(
    (int) $priority->query('SELECT copy_id FROM loans WHERE user_id = 3 AND status = "active"')->fetchColumn() === 302,
    'A ready reservation should take priority over an unrelated generally available copy.'
);
check_library_borrow(
    $priority->query('SELECT status FROM reservations WHERE id = 401')->fetchColumn() === 'fulfilled',
    'Borrowing a ready hold should fulfill that reservation.'
);
check_library_borrow(
    $priority->query('SELECT status FROM book_copies WHERE id = 301')->fetchColumn() === 'available',
    'Honoring the ready hold should leave the unrelated available copy in circulation.'
);

fwrite(STDOUT, "Library borrow workflow checks passed (14-day due date, three-loan cap, ready-hold priority).\n");
