<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/checkout.php';

function check_checkout_integrity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$activeCart = [
    ['book_id' => 1, 'is_active' => 1],
    ['book_id' => 2, 'is_active' => 1],
];
assert_checkout_cart_is_active($activeCart);
check_checkout_integrity(true, 'Checkout must accept cart lines for active titles.');

$inactiveCart = [
    ['book_id' => 1, 'is_active' => 1],
    ['book_id' => 2, 'is_active' => 0],
];
$rejected = false;
try {
    assert_checkout_cart_is_active($inactiveCart);
} catch (DomainException $exception) {
    $rejected = $exception->getMessage() === tr('error_title_unavailable');
}
check_checkout_integrity($rejected, 'Checkout must reject inactive titles so their cart rows are not silently cleared.');

fwrite(STDOUT, "2 checkout integrity checks passed.\n");

$addressDb = new PDO('sqlite::memory:');
$addressDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$addressDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$addressDb->exec(
    'CREATE TABLE addresses (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        label TEXT NOT NULL,
        recipient_name TEXT NOT NULL,
        line1 TEXT NOT NULL,
        line2 TEXT NULL,
        city TEXT NOT NULL,
        region TEXT NULL,
        postal_code TEXT NULL,
        country_code TEXT NOT NULL,
        is_default INTEGER NOT NULL DEFAULT 0
    )'
);
$addressDb->exec("INSERT INTO addresses VALUES (1, 7, 'Home', 'Reader One', '1 Paper Lane', NULL, 'Erbil', 'Kurdistan', '44001', 'IQ', 1)");
$addressDb->exec("INSERT INTO addresses VALUES (2, 8, 'Office', 'Other Reader', '2 Ink Road', NULL, 'Duhok', NULL, NULL, 'IQ', 1)");
$ownedAddress = load_checkout_address($addressDb, 7, 1);
check_checkout_integrity($ownedAddress !== null && $ownedAddress['recipient_name'] === 'Reader One', 'Checkout must load the explicitly selected saved address for its owner.');
check_checkout_integrity(load_checkout_address($addressDb, 7, 2) === null, 'Checkout must reject an address owned by another customer.');

$snapshot = checkout_shipping_address_snapshot($ownedAddress);
$snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$addressDb->exec("UPDATE addresses SET line1 = 'Changed later' WHERE id = 1");
$snapshotAfterEdit = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
check_checkout_integrity($snapshotAfterEdit['line1'] === '1 Paper Lane', 'An order address snapshot must preserve its checkout-time value after a saved address changes.');
check_checkout_integrity(array_keys($snapshot) === ['recipient_name', 'line1', 'line2', 'city', 'region', 'postal_code', 'country_code'], 'Order snapshots must include only the shipping fields needed to reproduce the delivery address.');

fwrite(STDOUT, "4 checkout address checks passed.\n");
