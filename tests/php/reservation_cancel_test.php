<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/library.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SQLite unavailable; reservation cancellation checks skipped.\n");
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE books (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE reservations (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, book_id INTEGER NOT NULL,
    status TEXT NOT NULL, queue_position INTEGER, created_at TEXT NOT NULL, ready_until TEXT
)');
$pdo->exec('CREATE TABLE activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, action TEXT,
    entity_type TEXT, entity_id INTEGER, details TEXT, ip_address BLOB
)');
$pdo->exec('INSERT INTO books (id) VALUES (10), (20)');
$pdo->exec("INSERT INTO reservations (id,user_id,book_id,status,queue_position,created_at) VALUES
    (1, 11, 10, 'waiting', 1, '2026-10-01'),
    (2, 12, 10, 'waiting', 2, '2026-10-02'),
    (3, 13, 10, 'waiting', 3, '2026-10-03'),
    (4, 11, 20, 'ready', NULL, '2026-10-01'),
    (5, 12, 20, 'fulfilled', NULL, '2026-10-02')");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$checkLocales = function () use (&$checkCount): void {
    $checkCount++;
    if (tr('cancel_reservation', 'en') !== 'Leave queue'
        || tr('cancel_reservation', 'ckb') === 'Leave queue'
        || tr('reservation_cancelled', 'ckb') === tr('reservation_cancelled', 'en')) {
        throw new RuntimeException('Reservation cancellation controls and feedback must be localized.');
    }
};

$checkCount = 0;
$check = static function (bool $condition, string $message) use (&$checkCount): void {
    $checkCount++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$checkLocales();
$reservationsPage = file_get_contents(__DIR__ . '/../../account/reservations.php');
$check(is_string($reservationsPage)
    && str_contains($reservationsPage, 'if ($reservation[\'status\'] === \'waiting\')')
    && str_contains($reservationsPage, '<?= csrf_field() ?>')
    && str_contains($reservationsPage, 'account/cancel_reservation.php'), 'Only waiting entries should show a CSRF-protected cancellation form.');
$check(cancel_waiting_reservation($pdo, 11, 1), 'The owner should be able to leave a waiting queue.');
$check($pdo->query('SELECT status FROM reservations WHERE id = 1')->fetchColumn() === 'cancelled', 'Cancellation should update the owned waiting reservation.');
$check($pdo->query('SELECT queue_position FROM reservations WHERE id = 2')->fetchColumn() === 1, 'The first remaining reservation should move to position one.');
$check($pdo->query('SELECT queue_position FROM reservations WHERE id = 3')->fetchColumn() === 2, 'The next remaining reservation should move to position two.');
$check((int) $pdo->query('SELECT COUNT(*) FROM activity_log WHERE action = "library.reservation_cancel" AND entity_id = 1')->fetchColumn() === 1, 'Cancellation should be recorded in the same transaction.');
$check(!cancel_waiting_reservation($pdo, 99, 2), 'A different member cannot cancel someone else\'s queue entry.');
$check(!cancel_waiting_reservation($pdo, 11, 4), 'A ready hold cannot be cancelled by the waiting-list action.');
$check(!cancel_waiting_reservation($pdo, 12, 5), 'A fulfilled reservation cannot be cancelled.');
$check((int) $pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() === 1, 'Rejected attempts must not create activity records.');

fwrite(STDOUT, "{$checkCount} reservation cancellation checks passed.\n");
