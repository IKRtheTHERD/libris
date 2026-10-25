<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

$helperPath = __DIR__ . '/../../admin/copy_inventory.php';
if (is_file($helperPath)) {
    require_once $helperPath;
}

function check_admin_inventory(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

check_admin_inventory(
    function_exists('change_copy_inventory_status'),
    'Staff must have a copy-level inventory status workflow.'
);

$route = file_get_contents(__DIR__ . '/../../admin/copies.php');
check_admin_inventory(str_contains($route, '$pageSize = 50;') && str_contains($route, 'LIMIT :limit OFFSET :offset'), 'Inventory listing must paginate beyond the first page.');
check_admin_inventory(is_string($route) && str_contains($route, 'require_staff();'), 'Inventory controls must require an active staff role.');
check_admin_inventory(str_contains($route, 'require_post();') && str_contains($route, 'require_valid_csrf();'), 'Inventory mutations must be POST-only and CSRF-protected.');
check_admin_inventory(str_contains((string) file_get_contents(__DIR__ . '/../../admin/books.php'), "app_url('admin/copies.php')"), 'Staff must be able to discover copy inventory from the Books & Copies screen.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE book_copies (
    id INTEGER PRIMARY KEY,
    book_id INTEGER NOT NULL,
    purpose TEXT NOT NULL,
    status TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER,
    action TEXT NOT NULL,
    entity_type TEXT,
    entity_id INTEGER,
    details TEXT,
    ip_address BLOB
)');
$pdo->exec("INSERT INTO book_copies (id, book_id, purpose, status) VALUES
    (1, 11, 'sale', 'available'),
    (2, 11, 'loan', 'available'),
    (3, 11, 'sale', 'sold'),
    (4, 11, 'loan', 'reserved'),
    (5, 11, 'loan', 'loaned'),
    (6, 11, 'loan', 'lost')");

change_copy_inventory_status($pdo, 1, 'available', 'lost', 7);
change_copy_inventory_status($pdo, 1, 'lost', 'available', 7);
change_copy_inventory_status($pdo, 6, 'lost', 'available', 7);
$events = $pdo->query("SELECT action, entity_type, entity_id, details FROM activity_log ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check_admin_inventory(count($events) === 3, 'Each successful staff status change must be audited.');
check_admin_inventory($events[0]['action'] === 'inventory.copy_status' && $events[0]['entity_type'] === 'book_copy' && (int) $events[0]['entity_id'] === 1, 'Audit entries must identify the copy status action and entity.');
$firstChange = json_decode((string) $events[0]['details'], true, 512, JSON_THROW_ON_ERROR);
check_admin_inventory($firstChange === ['book_id' => 11, 'purpose' => 'sale', 'from' => 'available', 'to' => 'lost'], 'Audit details must preserve title, purpose, and before/after status.');
$loanChange = json_decode((string) $events[2]['details'], true, 512, JSON_THROW_ON_ERROR);
check_admin_inventory($loanChange['purpose'] === 'loan' && $loanChange['from'] === 'lost' && $loanChange['to'] === 'available', 'Loan copies must retain their purpose in restoration events.');

$rejected = 0;
foreach ([3, 4, 5] as $copyId) {
    try {
        change_copy_inventory_status($pdo, $copyId, 'available', 'lost', 7);
    } catch (DomainException) {
        $rejected++;
    }
}
check_admin_inventory($rejected === 3, 'Sold, reserved, and loaned copies must stay under their order and loan workflows.');

$invalidTargetRejected = false;
try {
    change_copy_inventory_status($pdo, 2, 'available', 'sold', 7);
} catch (InvalidArgumentException) {
    $invalidTargetRejected = true;
}
check_admin_inventory($invalidTargetRejected, 'Staff must not submit a workflow-owned status as a manual target.');

$staleRejected = false;
try {
    change_copy_inventory_status($pdo, 1, 'lost', 'available', 7);
} catch (DomainException) {
    $staleRejected = true;
}
check_admin_inventory($staleRejected, 'Submissions from a stale status must be rejected.');

$noOpRejected = false;
try {
    change_copy_inventory_status($pdo, 1, 'available', 'available', 7);
} catch (DomainException) {
    $noOpRejected = true;
}
check_admin_inventory($noOpRejected, 'Repeated requests for the current status must be rejected.');

$pdo->exec("CREATE TRIGGER fail_inventory_audit BEFORE INSERT ON activity_log BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
$auditFailureRolledBack = false;
try {
    change_copy_inventory_status($pdo, 2, 'available', 'lost', 7);
} catch (PDOException) {
    $auditFailureRolledBack = true;
}
$copyStatus = $pdo->query('SELECT status FROM book_copies WHERE id = 2')->fetchColumn();
check_admin_inventory($auditFailureRolledBack && $copyStatus === 'available', 'Copy status must roll back when audit logging fails.');
check_admin_inventory(!$pdo->inTransaction(), 'Failed changes must not leak a transaction.');

fwrite(STDOUT, "Inventory workflow checks passed.\n");
