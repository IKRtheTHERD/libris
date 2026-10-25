<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function load_checkout_address(PDO $pdo, int $userId, int $addressId): ?array
{
    if ($userId < 1 || $addressId < 1) {
        return null;
    }

    $lockClause = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $statement = $pdo->prepare(
        'SELECT id, user_id, label, recipient_name, line1, line2, city, region, postal_code, country_code, is_default
         FROM addresses
         WHERE id = :id AND user_id = :user_id' . $lockClause
    );
    $statement->execute(['id' => $addressId, 'user_id' => $userId]);
    $address = $statement->fetch();

    return is_array($address) ? $address : null;
}

function checkout_shipping_address_snapshot(array $address): array
{
    foreach (['recipient_name', 'line1', 'city', 'country_code'] as $requiredField) {
        if (!isset($address[$requiredField]) || !is_string($address[$requiredField]) || trim($address[$requiredField]) === '') {
            throw new DomainException(tr('error_address_required'));
        }
    }

    return [
        'recipient_name' => $address['recipient_name'],
        'line1' => $address['line1'],
        'line2' => isset($address['line2']) && $address['line2'] !== '' ? $address['line2'] : null,
        'city' => $address['city'],
        'region' => isset($address['region']) && $address['region'] !== '' ? $address['region'] : null,
        'postal_code' => isset($address['postal_code']) && $address['postal_code'] !== '' ? $address['postal_code'] : null,
        'country_code' => $address['country_code'],
    ];
}

function assert_checkout_cart_is_active(array $cartItems): void
{
    foreach ($cartItems as $item) {
        if ((int) ($item['is_active'] ?? 0) !== 1) {
            throw new DomainException(tr('error_title_unavailable'));
        }
    }
}
