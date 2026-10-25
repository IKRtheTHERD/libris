<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../admin/book_deletion.php';

function check_book_deletion(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function book_deletion_database(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE catalog_guard (id INTEGER PRIMARY KEY, book_count INTEGER NOT NULL)');
    $pdo->exec('INSERT INTO catalog_guard (id, book_count) VALUES (1, 0)');
    $pdo->exec('CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TRIGGER books_before_insert AFTER INSERT ON books BEGIN UPDATE catalog_guard SET book_count = book_count + 1 WHERE id = 1; END');
    $pdo->exec('CREATE TRIGGER books_after_delete AFTER DELETE ON books BEGIN UPDATE catalog_guard SET book_count = MAX(book_count - 1, 0) WHERE id = 1; END');
    $pdo->exec('CREATE TABLE book_copies (id INTEGER PRIMARY KEY, book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE RESTRICT, purpose TEXT NOT NULL, status TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY, book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE RESTRICT, copy_id INTEGER NULL REFERENCES book_copies(id) ON DELETE SET NULL)');
    $pdo->exec('CREATE TABLE loans (id INTEGER PRIMARY KEY, copy_id INTEGER NOT NULL REFERENCES book_copies(id) ON DELETE RESTRICT)');
    $pdo->exec('CREATE TABLE reservations (id INTEGER PRIMARY KEY, book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE RESTRICT)');
    $pdo->exec('CREATE TABLE cart_items (id INTEGER PRIMARY KEY, book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE reviews (id INTEGER PRIMARY KEY, book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE wishlist (user_id INTEGER NOT NULL, book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE, PRIMARY KEY (user_id, book_id))');
    $pdo->exec('CREATE TABLE book_authors (book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE, author_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE book_categories (book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE, category_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, action TEXT NOT NULL, entity_type TEXT, entity_id INTEGER, details TEXT, ip_address BLOB)');

    return $pdo;
}

function add_book(PDO $pdo, int $id, string $title, array $copyStatuses = []): void
{
    $insertBook = $pdo->prepare('INSERT INTO books (id, title) VALUES (:id, :title)');
    $insertBook->execute(['id' => $id, 'title' => $title]);
    $insertCopy = $pdo->prepare('INSERT INTO book_copies (id, book_id, purpose, status) VALUES (:id, :book_id, :purpose, :status)');
    foreach ($copyStatuses as $index => [$purpose, $status]) {
        $insertCopy->execute([
            'id' => ($id * 100) + $index + 1,
            'book_id' => $id,
            'purpose' => $purpose,
            'status' => $status,
        ]);
    }
}

$bookRoute = file_get_contents(__DIR__ . '/../../admin/books.php');
$labels = file_get_contents(__DIR__ . '/../../includes/i18n.php');
check_book_deletion(is_string($bookRoute), 'The staff book list must remain readable.');
check_book_deletion(
    str_contains($bookRoute, 'require_staff();')
    && str_contains($bookRoute, 'require_post();')
    && str_contains($bookRoute, 'require_valid_csrf();'),
    'Title deletion must remain staff-only, POST-only, and CSRF-protected.'
);
check_book_deletion(
    str_contains($bookRoute, 'delete_unused_book(')
    && str_contains($bookRoute, "'delete'")
    && str_contains($bookRoute, 'delete_eligible')
    && str_contains($bookRoute, 'escape_html((string) json_encode'),
    'The staff list must delegate deletion to the guarded helper and offer it only when eligible.'
);
check_book_deletion(
    is_string($labels)
    && str_contains($labels, "'delete_title'")
    && str_contains($labels, "'title_deleted'")
    && str_contains($labels, "'title_delete_archive_required'"),
    'Delete controls and blocked-history feedback need translation keys.'
);

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$pdo = book_deletion_database();
add_book($pdo, 1, 'Unused title', [['sale', 'available'], ['loan', 'available']]);
add_book($pdo, 2, 'Surviving title');
$pdo->exec("INSERT INTO book_authors (book_id, author_id) VALUES (1, 9)");
$pdo->exec("INSERT INTO book_categories (book_id, category_id) VALUES (1, 4)");
$removedCopies = delete_unused_book($pdo, 1, 12);
check_book_deletion($removedCopies === 2, 'An unused title must return the number of safe copies removed.');
check_book_deletion($pdo->query('SELECT COUNT(*) FROM books WHERE id = 1')->fetchColumn() === 0, 'An unused title must be permanently deleted.');
check_book_deletion($pdo->query('SELECT COUNT(*) FROM book_copies WHERE book_id = 1')->fetchColumn() === 0, 'Available sale and loan copies must be removed with the title.');
check_book_deletion($pdo->query('SELECT book_count FROM catalog_guard WHERE id = 1')->fetchColumn() === 1, 'The schema delete trigger must free one physical title slot.');
check_book_deletion($pdo->query('SELECT COUNT(*) FROM book_authors WHERE book_id = 1')->fetchColumn() === 0, 'Book-author links must cascade on safe title deletion.');
check_book_deletion($pdo->query('SELECT COUNT(*) FROM book_categories WHERE book_id = 1')->fetchColumn() === 0, 'Book-category links must cascade on safe title deletion.');
$audit = $pdo->query("SELECT action, entity_type, entity_id, details FROM activity_log WHERE action = 'catalog.delete'")->fetch();
check_book_deletion(
    is_array($audit)
    && $audit['entity_type'] === 'book'
    && (int) $audit['entity_id'] === 1
    && (json_decode((string) $audit['details'], true)['title'] ?? null) === 'Unused title'
    && (json_decode((string) $audit['details'], true)['removed_available_copies'] ?? null) === 2,
    'Deletion must atomically audit the removed title and its available-copy count.'
);

foreach (['sold', 'reserved', 'loaned', 'lost', 'retired'] as $status) {
    $blockedPdo = book_deletion_database();
    add_book($blockedPdo, 3, 'Title with ' . $status . ' stock', [['sale', $status]]);
    $rejected = false;
    try {
        delete_unused_book($blockedPdo, 3, 12);
    } catch (DomainException $exception) {
        $rejected = $exception->getMessage() === tr('title_delete_archive_required');
    }
    check_book_deletion($rejected, 'A title with ' . $status . ' inventory must be retained for archive.');
    check_book_deletion($blockedPdo->query('SELECT COUNT(*) FROM books WHERE id = 3')->fetchColumn() === 1, 'Rejected inventory deletion must preserve the title.');
    check_book_deletion($blockedPdo->query('SELECT book_count FROM catalog_guard WHERE id = 1')->fetchColumn() === 1, 'Rejected inventory deletion must preserve the title cap count.');
    check_book_deletion(!$blockedPdo->inTransaction(), 'Rejected deletion must close its transaction.');
}

$referenceCases = [
    'order history' => static function (PDO $db): void {
        $db->exec('INSERT INTO order_items (id, book_id) VALUES (1, 4)');
    },
    'loan history' => static function (PDO $db): void {
        $db->exec('INSERT INTO loans (id, copy_id) VALUES (1, 401)');
    },
    'reservation history' => static function (PDO $db): void {
        $db->exec('INSERT INTO reservations (id, book_id) VALUES (1, 4)');
    },
    'member cart data' => static function (PDO $db): void {
        $db->exec('INSERT INTO cart_items (id, book_id) VALUES (1, 4)');
    },
    'reviews' => static function (PDO $db): void {
        $db->exec('INSERT INTO reviews (id, book_id) VALUES (1, 4)');
    },
    'wishlists' => static function (PDO $db): void {
        $db->exec('INSERT INTO wishlist (user_id, book_id) VALUES (7, 4)');
    },
];
foreach ($referenceCases as $referenceName => $addReference) {
    $blockedPdo = book_deletion_database();
    add_book($blockedPdo, 4, 'Title with ' . $referenceName, [['sale', 'available']]);
    $addReference($blockedPdo);
    $rejected = false;
    try {
        delete_unused_book($blockedPdo, 4, 12);
    } catch (DomainException $exception) {
        $rejected = $exception->getMessage() === tr('title_delete_archive_required');
    }
    check_book_deletion($rejected, 'A title with ' . $referenceName . ' must be retained for archive.');
    check_book_deletion($blockedPdo->query('SELECT COUNT(*) FROM books WHERE id = 4')->fetchColumn() === 1, 'Historical-reference rejection must preserve the title.');
    check_book_deletion($blockedPdo->query('SELECT COUNT(*) FROM book_copies WHERE book_id = 4')->fetchColumn() === 1, 'Historical-reference rejection must preserve its available copy.');
}

$auditFailurePdo = book_deletion_database();
add_book($auditFailurePdo, 5, 'Audit failure title', [['sale', 'available']]);
$auditFailurePdo->exec("CREATE TRIGGER reject_book_delete_audit BEFORE INSERT ON activity_log BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END");
$auditRolledBack = false;
try {
    delete_unused_book($auditFailurePdo, 5, 12);
} catch (PDOException) {
    $auditRolledBack = true;
}
check_book_deletion($auditRolledBack, 'An audit-write failure must fail the delete.');
check_book_deletion($auditFailurePdo->query('SELECT COUNT(*) FROM books WHERE id = 5')->fetchColumn() === 1, 'An audit-write failure must restore the title.');
check_book_deletion($auditFailurePdo->query('SELECT COUNT(*) FROM book_copies WHERE book_id = 5')->fetchColumn() === 1, 'An audit-write failure must restore its removed copy.');
check_book_deletion($auditFailurePdo->query('SELECT book_count FROM catalog_guard WHERE id = 1')->fetchColumn() === 1, 'An audit-write failure must roll back the title-cap trigger effect.');
check_book_deletion(!$auditFailurePdo->inTransaction(), 'An audit-write failure must close its transaction.');

$missingPdo = book_deletion_database();
$missingRejected = false;
try {
    delete_unused_book($missingPdo, 99, 12);
} catch (DomainException) {
    $missingRejected = true;
}
check_book_deletion($missingRejected, 'Unknown title IDs must be rejected.');
$invalidActorRejected = false;
try {
    delete_unused_book($missingPdo, 1, 0);
} catch (InvalidArgumentException) {
    $invalidActorRejected = true;
}
check_book_deletion($invalidActorRejected, 'Invalid staff actor IDs must be rejected.');

fwrite(STDOUT, "Safe book deletion checks passed.\n");
