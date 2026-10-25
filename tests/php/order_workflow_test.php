<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../orders/staff_workflow.php';

function check_staff_order(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expect_staff_order_failure(callable $action, string $exceptionClass, string $message): void
{
    try {
        $action();
    } catch (Throwable $exception) {
        check_staff_order($exception instanceof $exceptionClass, $message);
        return;
    }

    check_staff_order(false, $message);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT NOT NULL, total_iqd INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, status TEXT NOT NULL, amount_iqd INTEGER NOT NULL, processed_at TEXT NULL)');
$pdo->exec('CREATE TABLE book_copies (id INTEGER PRIMARY KEY, book_id INTEGER NOT NULL, purpose TEXT NOT NULL, status TEXT NOT NULL)');
$pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, book_id INTEGER NOT NULL, copy_id INTEGER NULL, title_snapshot TEXT NOT NULL, unit_price_iqd INTEGER NOT NULL, quantity INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, action TEXT NOT NULL, entity_type TEXT, entity_id INTEGER, details TEXT, ip_address BLOB)');
$pdo->exec("INSERT INTO orders VALUES
    (1, 'paid', 12500),
    (2, 'completed', 9000),
    (3, 'ready', 7000),
    (4, 'ready', 5000),
    (5, 'processing', 5000)");
$pdo->exec("INSERT INTO payments (id, order_id, status, amount_iqd) VALUES
    (1, 1, 'succeeded', 12500),
    (2, 2, 'succeeded', 9000),
    (3, 3, 'succeeded', 7000),
    (4, 4, 'succeeded', 5000),
    (5, 5, 'succeeded', 5000)");
$pdo->exec("INSERT INTO book_copies VALUES
    (101, 11, 'sale', 'sold'),
    (102, 12, 'sale', 'sold'),
    (103, 13, 'loan', 'available'),
    (104, 14, 'sale', 'sold'),
    (105, 15, 'sale', 'lost'),
    (106, 16, 'sale', 'sold')");
$pdo->exec("INSERT INTO order_items VALUES
    (1, 1, 11, 101, 'Book 11', 5000, 1),
    (2, 1, 12, 102, 'Book 12', 7500, 1),
    (3, 2, 14, 104, 'Book 14', 9000, 1),
    (4, 3, 15, 105, 'Book 15', 7000, 1),
    (5, 4, 16, 106, 'Book 16', 2500, 1),
    (6, 4, 16, 106, 'Book 16 duplicate', 2500, 1),
    (7, 5, 16, 106, 'Book 16 reused', 5000, 1)");

check_staff_order(
    apply_staff_order_action($pdo, 1, 'advance', 'processing', 7) === 'processing',
    'Staff can advance a paid order by exactly one fulfillment step.'
);
check_staff_order(
    $pdo->query('SELECT status FROM book_copies WHERE id IN (101, 102) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['sold', 'sold']
    && $pdo->query('SELECT status FROM payments WHERE id = 1')->fetchColumn() === 'succeeded',
    'Advancing fulfillment must not alter paid status or allocated inventory.'
);

check_staff_order(
    apply_staff_order_action($pdo, 1, 'refund', null, 7) === 'refunded',
    'Staff can refund a paid order before fulfillment is completed.'
);
check_staff_order(
    $pdo->query('SELECT status FROM book_copies WHERE id IN (101, 102) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['available', 'available']
    && $pdo->query('SELECT status FROM book_copies WHERE id = 103')->fetchColumn() === 'available'
    && $pdo->query('SELECT status FROM payments WHERE id = 1')->fetchColumn() === 'refunded'
    && $pdo->query('SELECT status FROM orders WHERE id = 1')->fetchColumn() === 'refunded',
    'Refund must restore only this order’s sale copies and update payment and order together.'
);
$refundActivity = $pdo->query("SELECT details FROM activity_log WHERE entity_id = 1 AND json_extract(details, '$.action') = 'refund'")->fetchColumn();
check_staff_order(is_string($refundActivity), 'Refund outcome must be included in the order activity history.');

expect_staff_order_failure(
    static fn () => apply_staff_order_action($pdo, 1, 'refund', null, 7),
    DomainException::class,
    'A refunded order cannot be refunded a second time.'
);
expect_staff_order_failure(
    static fn () => apply_staff_order_action($pdo, 2, 'refund', null, 7),
    DomainException::class,
    'A completed order cannot restore inventory through the refund action.'
);
check_staff_order(
    $pdo->query('SELECT status FROM book_copies WHERE id = 104')->fetchColumn() === 'sold'
    && $pdo->query('SELECT status FROM payments WHERE id = 2')->fetchColumn() === 'succeeded',
    'Rejected completed-order refunds must leave stock and payment untouched.'
);

expect_staff_order_failure(
    static fn () => apply_staff_order_action($pdo, 3, 'refund', null, 7),
    RuntimeException::class,
    'A refund must fail closed if a referenced copy is not sold.'
);
check_staff_order(
    $pdo->query('SELECT status FROM orders WHERE id = 3')->fetchColumn() === 'ready'
    && $pdo->query('SELECT status FROM book_copies WHERE id = 105')->fetchColumn() === 'lost'
    && $pdo->query('SELECT status FROM payments WHERE id = 3')->fetchColumn() === 'succeeded',
    'Inventory inconsistency must roll back the full refund transaction.'
);

expect_staff_order_failure(
    static fn () => apply_staff_order_action($pdo, 4, 'refund', null, 7),
    RuntimeException::class,
    'A refund must fail closed if a physical copy is referenced more than once.'
);
check_staff_order(
    $pdo->query('SELECT status FROM orders WHERE id = 4')->fetchColumn() === 'ready'
    && $pdo->query('SELECT status FROM book_copies WHERE id = 106')->fetchColumn() === 'sold'
    && $pdo->query('SELECT status FROM payments WHERE id = 4')->fetchColumn() === 'succeeded',
    'Duplicate inventory references must not create stock or payment drift.'
);

expect_staff_order_failure(
    static fn () => apply_staff_order_action($pdo, 5, 'advance', 'completed', 7),
    DomainException::class,
    'Staff cannot skip fulfillment states by tampering with the submitted target.'
);

$orderHistory = (string) file_get_contents(__DIR__ . '/../../orders/index.php');
$orderDetails = (string) file_get_contents(__DIR__ . '/../../orders/show.php');
$staffRoute = (string) file_get_contents(__DIR__ . '/../../admin/orders.php');
check_staff_order(
    str_contains($orderHistory, 'require_authentication();') && str_contains($orderHistory, 'WHERE user_id = :user_id'),
    'Order history must require an active account and return only that member’s orders.'
);
check_staff_order(
    str_contains($orderDetails, 'require_authentication();') && str_contains($orderDetails, 'WHERE id = :id AND user_id = :user_id'),
    'Order detail must enforce ownership when loading the selected order.'
);
check_staff_order(
    str_contains($staffRoute, 'require_staff();')
    && str_contains($staffRoute, 'require_post();')
    && str_contains($staffRoute, 'require_valid_csrf();')
    && str_contains($staffRoute, 'name="action" value="refund"'),
    'The refund control must remain behind staff authorization, POST, and CSRF checks.'
);

fwrite(STDOUT, "16 staff order workflow checks passed.\n");
