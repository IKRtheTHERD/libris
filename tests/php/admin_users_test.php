<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin/user_management.php';

function check_admin_users(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$route = file_get_contents(__DIR__ . '/../../admin/users.php');
check_admin_users(is_string($route), 'The admin user-management route must be readable.');
check_admin_users(
    str_contains($route, "(current_user()['role'] ?? '') !== 'admin'"),
    'User management must remain restricted to administrators.'
);
check_admin_users(
    is_int(strpos($route, 'require_post();'))
        && is_int(strpos($route, 'require_valid_csrf();'))
        && strpos($route, 'require_post();') < strpos($route, 'require_valid_csrf();'),
    'Account status changes must be POST-only and CSRF-protected.'
);
check_admin_users(
    str_contains($route, 'set_customer_account_active(')
        && str_contains($route, "'activate', 'deactivate'"),
    'The route must validate the requested action and delegate to the transactional status handler.'
);
check_admin_users(
    str_contains($route, 'FROM users u WHERE u.role = \'customer\'')
        && str_contains($route, '<?= csrf_field() ?>')
        && str_contains($route, "escape_html(app_url('admin/users.php'))"),
    'The page must list only customers and submit status changes through an escaped CSRF form.'
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL, is_active INTEGER NOT NULL)');
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
$pdo->exec("INSERT INTO users (id, role, is_active) VALUES (1, 'admin', 1), (2, 'customer', 0), (3, 'librarian', 1), (4, 'customer', 1)");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

check_admin_users(
    set_customer_account_active($pdo, 1, 2, true),
    'An administrator must be able to activate an inactive customer.'
);
check_admin_users(
    (int) $pdo->query('SELECT is_active FROM users WHERE id = 2')->fetchColumn() === 1,
    'Activation must persist the new account status.'
);
$event = $pdo->query('SELECT actor_user_id, action, entity_type, entity_id FROM activity_log')->fetch();
check_admin_users(
    $event === ['actor_user_id' => 1, 'action' => 'member.activate', 'entity_type' => 'user', 'entity_id' => 2],
    'A successful status change must record its actor, action, and target.'
);
check_admin_users(
    !set_customer_account_active($pdo, 1, 2, true)
        && (int) $pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() === 1,
    'Repeating the current status must be a safe no-op without a duplicate audit event.'
);
check_admin_users(
    set_customer_account_active($pdo, 1, 2, false)
        && (int) $pdo->query('SELECT is_active FROM users WHERE id = 2')->fetchColumn() === 0,
    'An administrator must be able to deactivate an active customer.'
);

$selfRejected = false;
try {
    set_customer_account_active($pdo, 1, 1, false);
} catch (InvalidArgumentException) {
    $selfRejected = true;
}
check_admin_users($selfRejected, 'An administrator must not change their own account through this action.');

$staffRejected = false;
try {
    set_customer_account_active($pdo, 1, 3, false);
} catch (DomainException) {
    $staffRejected = true;
}
check_admin_users($staffRejected, 'Customer status controls must not alter staff accounts.');

$missingRejected = false;
try {
    set_customer_account_active($pdo, 1, 99, false);
} catch (DomainException) {
    $missingRejected = true;
}
check_admin_users($missingRejected, 'Unknown account IDs must be rejected.');

$pdo->exec(
    "CREATE TRIGGER reject_user_audit BEFORE INSERT ON activity_log
     WHEN NEW.entity_id = 4 BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END"
);
$auditFailureRolledBack = false;
try {
    set_customer_account_active($pdo, 1, 4, false);
} catch (PDOException) {
    $auditFailureRolledBack = (int) $pdo->query('SELECT is_active FROM users WHERE id = 4')->fetchColumn() === 1
        && !$pdo->inTransaction();
}
check_admin_users(
    $auditFailureRolledBack,
    'A failed audit write must roll back the account status update and close its transaction.'
);

echo "Admin user-management checks passed.\n";
