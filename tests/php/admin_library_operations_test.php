<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin/library_operations.php';

function check_admin_library_operations(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$loanRoute = file_get_contents(__DIR__ . '/../../admin/loans.php');
$reservationRoute = file_get_contents(__DIR__ . '/../../admin/reservations.php');
$dashboardRoute = file_get_contents(__DIR__ . '/../../admin/index.php');
check_admin_library_operations(is_string($loanRoute) && str_contains($loanRoute, 'require_staff();'), 'Loan desk must require staff.');
check_admin_library_operations(str_contains($loanRoute, 'require_post();') && str_contains($loanRoute, 'require_valid_csrf();'), 'Loan state changes must use POST and CSRF.');
check_admin_library_operations(str_contains($loanRoute, 'mark_loan_copy_lost(') && str_contains($loanRoute, 'settle_staff_fine('), 'Loan desk must expose lost-copy and unpaid-fine actions.');
check_admin_library_operations(is_string($reservationRoute) && str_contains($reservationRoute, 'require_staff();'), 'Reservation desk must require staff.');
check_admin_library_operations(str_contains($reservationRoute, 'require_post();') && str_contains($reservationRoute, 'require_valid_csrf();'), 'Reservation state changes must use POST and CSRF.');
check_admin_library_operations(str_contains($reservationRoute, 'operate_staff_reservation(') && str_contains($reservationRoute, '$pageSize = 50;'), 'Reservation worklist must use the transactional helper and paginate.');
check_admin_library_operations(is_string($dashboardRoute) && str_contains($dashboardRoute, "['reservations.php','manage_reservations']"), 'Staff must be able to reach reservation worklist from dashboard.');
check_admin_library_operations(
    function_exists('mark_loan_copy_lost')
    && function_exists('operate_staff_reservation')
    && function_exists('settle_staff_fine'),
    'Staff loan, reservation, and fine operations must have service helpers.'
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE books (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT)');
$pdo->exec('CREATE TABLE book_copies (
    id INTEGER PRIMARY KEY,
    book_id INTEGER NOT NULL,
    purpose TEXT NOT NULL,
    status TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE loans (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    copy_id INTEGER NOT NULL,
    borrowed_at TEXT NOT NULL,
    due_at TEXT NOT NULL,
    returned_at TEXT,
    status TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE reservations (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    book_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    queue_position INTEGER,
    created_at TEXT NOT NULL,
    ready_until TEXT
)');
$pdo->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)');
$pdo->exec('CREATE TABLE fines (
    id INTEGER PRIMARY KEY,
    loan_id INTEGER NOT NULL UNIQUE,
    amount_iqd INTEGER NOT NULL,
    status TEXT NOT NULL,
    assessed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    settled_at TEXT
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
$pdo->exec("INSERT INTO books (id) VALUES (1), (2), (3)");
$pdo->exec("INSERT INTO users (id, first_name, last_name, email) VALUES
    (1, 'Ada', 'Reader', 'ada@example.test'),
    (2, 'Sam', 'Reader', 'sam@example.test')");
$pdo->exec("INSERT INTO book_copies (id, book_id, purpose, status) VALUES
    (1, 1, 'loan', 'loaned'),
    (2, 1, 'loan', 'loaned'),
    (3, 2, 'loan', 'available'),
    (4, 2, 'loan', 'reserved'),
    (5, 3, 'loan', 'loaned'),
    (6, 2, 'loan', 'reserved')");
$today = new DateTimeImmutable((string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn());
$todayText = $today->format('Y-m-d');
$pastText = $today->modify('-1 day')->format('Y-m-d');
$borrowedEarlier = $today->modify('-14 days')->format('Y-m-d');
$futureText = $today->modify('+5 days')->format('Y-m-d');
$readyUntil = $today->modify('+3 days')->format('Y-m-d');
$insertLoan = $pdo->prepare('INSERT INTO loans (id, user_id, copy_id, borrowed_at, due_at, status) VALUES (?, ?, ?, ?, ?, ?)');
$insertLoan->execute([1, 1, 1, $todayText, $futureText, 'active']);
$insertLoan->execute([2, 2, 2, $todayText, $futureText, 'active']);
$insertLoan->execute([3, 1, 5, $borrowedEarlier, $pastText, 'active']);
$insertReservation = $pdo->prepare('INSERT INTO reservations (id, user_id, book_id, status, queue_position, created_at, ready_until) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insertReservation->execute([20, 1, 2, 'waiting', 1, '2026-10-20 09:00:00', null]);
$insertReservation->execute([21, 2, 2, 'waiting', 2, '2026-10-21 09:00:00', null]);
$insertReservation->execute([22, 1, 2, 'ready', null, '2026-10-19 09:00:00', $pastText]);
$insertReservation->execute([23, 1, 2, 'ready', null, '2026-10-18 09:00:00', $readyUntil]);
$insertReservation->execute([24, 1, 2, 'waiting', 3, '2026-10-22 09:00:00', null]);
$insertReservation->execute([25, 2, 2, 'waiting', 4, '2026-10-23 09:00:00', null]);
$insertReservation->execute([26, 1, 2, 'waiting', 5, '2026-10-24 09:00:00', null]);
$insertReservation->execute([30, 1, 3, 'waiting', 1, '2026-10-20 09:00:00', null]);
$insertReservation->execute([31, 2, 3, 'waiting', 2, '2026-10-21 09:00:00', null]);
$insertFine = $pdo->prepare('INSERT INTO fines (id, loan_id, amount_iqd, status) VALUES (?, ?, ?, ?)');
$insertFine->execute([1, 1, 2000, 'unpaid']);
$insertFine->execute([2, 2, 3000, 'unpaid']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

mark_loan_copy_lost($pdo, 1, 9);
check_admin_library_operations($pdo->query('SELECT status FROM loans WHERE id = 1')->fetchColumn() === 'lost', 'Lost action must close the active loan as lost.');
check_admin_library_operations($pdo->query('SELECT status FROM book_copies WHERE id = 1')->fetchColumn() === 'lost', 'Lost action must remove the checked-out physical copy from available circulation.');
check_admin_library_operations((int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'library.loan_lost' AND actor_user_id = 9 AND entity_id = 1")->fetchColumn() === 1, 'Lost action must record its staff actor.');
$staleLoanRejected = false;
try {
    mark_loan_copy_lost($pdo, 1, 9);
} catch (DomainException) {
    $staleLoanRejected = true;
}
check_admin_library_operations($staleLoanRejected, 'Repeated loan-loss submission must be rejected.');

$prepared = operate_staff_reservation($pdo, 20, 'waiting', 'prepare', 9);
check_admin_library_operations($prepared['action'] === 'prepared', 'Staff may prepare only the first waiting reservation when a loan copy is free.');
check_admin_library_operations($pdo->query('SELECT status FROM reservations WHERE id = 20')->fetchColumn() === 'ready', 'Prepared reservation must enter ready state.');
check_admin_library_operations($pdo->query('SELECT status FROM book_copies WHERE id = 3')->fetchColumn() === 'reserved', 'Preparing a hold must reserve a real loan copy.');
check_admin_library_operations($pdo->query('SELECT queue_position FROM reservations WHERE id = 21')->fetchColumn() === 1, 'Preparing the first hold must compact the remaining waiting queue.');
$expired = operate_staff_reservation($pdo, 22, 'ready', 'expire', 9);
check_admin_library_operations($expired['action'] === 'expired' && $expired['promoted_reservation_id'] === 21, 'Expired hold must promote the next waiting member.');
check_admin_library_operations($pdo->query('SELECT status FROM reservations WHERE id = 22')->fetchColumn() === 'expired', 'Only past-due ready holds may expire.');
check_admin_library_operations($pdo->query('SELECT status FROM reservations WHERE id = 21')->fetchColumn() === 'ready', 'Promotion after expiry must ready the oldest waiting member.');
check_admin_library_operations((int) $pdo->query("SELECT COUNT(*) FROM book_copies WHERE book_id = 2 AND purpose = 'loan' AND status = 'reserved'")->fetchColumn() === 3, 'Expired hold replacement must preserve one reserved copy per ready reservation.');
$earlyExpiryRejected = false;
try {
    operate_staff_reservation($pdo, 23, 'ready', 'expire', 9);
} catch (DomainException) {
    $earlyExpiryRejected = true;
}
check_admin_library_operations($earlyExpiryRejected, 'Ready reservations cannot expire before their collection date passes.');
$cancelled = operate_staff_reservation($pdo, 20, 'ready', 'cancel', 9);
check_admin_library_operations($cancelled['action'] === 'cancelled', 'Staff may cancel a ready hold.');
check_admin_library_operations($pdo->query('SELECT status FROM reservations WHERE id = 20')->fetchColumn() === 'cancelled', 'Ready-hold cancellation must update reservation state.');
$reservedAfterCancel = (int) $pdo->query("SELECT COUNT(*) FROM book_copies WHERE book_id = 2 AND purpose = 'loan' AND status = 'reserved'")->fetchColumn();
check_admin_library_operations($reservedAfterCancel === 3, 'Ready-hold cancellation must promote the next waiter and preserve one reserved copy per ready reservation.');
check_admin_library_operations($pdo->query('SELECT status FROM reservations WHERE id = 24')->fetchColumn() === 'ready', 'Ready-hold cancellation must promote the oldest waiting reservation.');
$waitingCancelled = operate_staff_reservation($pdo, 25, 'waiting', 'cancel', 9);
check_admin_library_operations($waitingCancelled['action'] === 'cancelled', 'Staff may cancel a waiting hold.');
check_admin_library_operations($pdo->query('SELECT queue_position FROM reservations WHERE id = 26')->fetchColumn() === 1, 'Waiting-hold cancellation must compact the remaining queue.');

$paid = settle_staff_fine($pdo, 1, 'paid', 9);
check_admin_library_operations($paid, 'Staff may record an unpaid fine as paid.');
check_admin_library_operations($pdo->query('SELECT status FROM fines WHERE id = 1')->fetchColumn() === 'paid', 'Paid fine must retain its settlement state.');
check_admin_library_operations($pdo->query('SELECT settled_at FROM fines WHERE id = 1')->fetchColumn() !== null, 'Fine settlement must record a timestamp.');
$waived = settle_staff_fine($pdo, 2, 'waived', 9);
check_admin_library_operations($waived, 'Staff may waive an unpaid fine.');
$repeatSettlementRejected = false;
try {
    settle_staff_fine($pdo, 1, 'waived', 9);
} catch (DomainException) {
    $repeatSettlementRejected = true;
}
check_admin_library_operations($repeatSettlementRejected, 'Settled fines must not be changed through the unpaid-fine action.');

$returnFine = return_book_loan($pdo, 3, 9);
check_admin_library_operations($returnFine === 1000, 'Late return must assess one 1,000 IQD fine for one overdue day.');
check_admin_library_operations((int) $pdo->query("SELECT amount_iqd FROM fines WHERE loan_id = 3 AND status = 'unpaid'")->fetchColumn() === 1000, 'Late return must persist the unpaid fine.');
check_admin_library_operations($pdo->query('SELECT status FROM book_copies WHERE id = 5')->fetchColumn() === 'reserved', 'Return must reserve the physical copy for the next waiting member.');
check_admin_library_operations($pdo->query('SELECT status FROM reservations WHERE id = 30')->fetchColumn() === 'ready', 'Return must ready the first queued reservation.');
check_admin_library_operations($pdo->query('SELECT queue_position FROM reservations WHERE id = 30')->fetchColumn() === null, 'A ready reservation must not retain a queue position.');
check_admin_library_operations($pdo->query('SELECT queue_position FROM reservations WHERE id = 31')->fetchColumn() === 1, 'Return must compact remaining waiting positions.');
check_admin_library_operations((int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'library.reservation_ready' AND entity_id = 30")->fetchColumn() === 1, 'Automatic reservation readiness must be auditable.');

$pdo->exec("CREATE TRIGGER reject_loan_loss_audit BEFORE INSERT ON activity_log WHEN NEW.action = 'library.loan_lost' AND NEW.entity_id = 2 BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END");
$auditRollbackObserved = false;
try {
    mark_loan_copy_lost($pdo, 2, 9);
} catch (PDOException) {
    $auditRollbackObserved = $pdo->query('SELECT status FROM loans WHERE id = 2')->fetchColumn() === 'active'
        && $pdo->query('SELECT status FROM book_copies WHERE id = 2')->fetchColumn() === 'loaned'
        && !$pdo->inTransaction();
}
check_admin_library_operations($auditRollbackObserved, 'Loan/copy loss changes must roll back atomically when audit logging fails.');

fwrite(STDOUT, "Admin library operations checks passed.\n");
