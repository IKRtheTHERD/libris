<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/checkout.php';
require_once __DIR__ . '/includes/layout.php';

require_authentication();
$userId = (int) current_user()['id'];
$databaseUnavailable = false;
$addresses = [];
$items = [];
$total = 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    require_valid_csrf();
    $addressId = filter_var($_POST['address_id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($addressId) || $addressId < 1) {
        set_flash('error', tr('required_fields'));
        redirect_to(app_url('checkout.php'));
    }

    try {
        $pdo = db();
    } catch (PDOException) {
        set_flash('error', tr('error_generic'));
        redirect_to(app_url('cart/index.php'));
    }
    $pdo->beginTransaction();
    try {
$address = load_checkout_address($pdo, $userId, $addressId);
        if (!$address) {
            throw new DomainException(tr('error_address_required'));
        }

        $cartQuery = $pdo->prepare(
            'SELECT ci.book_id, ci.quantity, b.title, b.sale_price_iqd, b.is_active
             FROM cart_items ci JOIN books b ON b.id = ci.book_id
             WHERE ci.user_id = :user_id FOR UPDATE'
        );
        $cartQuery->execute(['user_id' => $userId]);
        $cartItems = $cartQuery->fetchAll();
        if ($cartItems === []) {
            throw new DomainException(tr('error_bag_empty'));
        }
        assert_checkout_cart_is_active($cartItems);

        $subtotal = 0;
        $allocatedCopies = [];
        foreach ($cartItems as $item) {
            $quantity = (int) $item['quantity'];
            if ($item['sale_price_iqd'] === null) {
                throw new DomainException(tr('error_bag_not_for_sale'));
            }
            $copyQuery = $pdo->prepare(
                "SELECT id FROM book_copies WHERE book_id = :book_id AND purpose = 'sale' AND status = 'available'
                 ORDER BY id LIMIT :copy_count FOR UPDATE"
            );
            $copyQuery->bindValue(':book_id', (int) $item['book_id'], PDO::PARAM_INT);
            $copyQuery->bindValue(':copy_count', $quantity, PDO::PARAM_INT);
            $copyQuery->execute();
            $copyIds = array_map('intval', $copyQuery->fetchAll(PDO::FETCH_COLUMN));
            if (count($copyIds) !== $quantity) {
                throw new DomainException(tr('error_sale_stock_changed'));
            }
            $price = (int) $item['sale_price_iqd'];
            $subtotal += $price * $quantity;
            $allocatedCopies[] = ['book_id' => (int) $item['book_id'], 'title' => (string) $item['title'], 'price' => $price, 'copy_ids' => $copyIds];
        }
        if ($subtotal > 4294967295) {
            throw new DomainException(tr('error_order_total_limit'));
        }

$addressSnapshot = json_encode(
    checkout_shipping_address_snapshot($address),
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
);
        $orderNumber = 'LIB-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $createOrder = $pdo->prepare(
            "INSERT INTO orders (user_id, address_id, shipping_address_snapshot, order_number, status, subtotal_iqd, total_iqd, currency)
             VALUES (:user_id, :address_id, :address_snapshot, :order_number, 'pending', :subtotal, :total, 'IQD')"
        );
        $createOrder->execute([
            'user_id' => $userId,
            'address_id' => $addressId,
            'address_snapshot' => $addressSnapshot,
            'order_number' => $orderNumber,
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        $addOrderItem = $pdo->prepare(
            'INSERT INTO order_items (order_id, book_id, copy_id, title_snapshot, unit_price_iqd, quantity)
             VALUES (:order_id, :book_id, :copy_id, :title, :price, 1)'
        );
        $markSold = $pdo->prepare("UPDATE book_copies SET status = 'sold' WHERE id = :id AND status = 'available'");
        foreach ($allocatedCopies as $line) {
            foreach ($line['copy_ids'] as $copyId) {
                $addOrderItem->execute([
                    'order_id' => $orderId,
                    'book_id' => $line['book_id'],
                    'copy_id' => $copyId,
                    'title' => $line['title'],
                    'price' => $line['price'],
                ]);
                $markSold->execute(['id' => $copyId]);
                if ($markSold->rowCount() !== 1) {
                    throw new DomainException(tr('error_sale_copy_changed'));
                }
            }
        }

        $reference = 'SIM-' . strtoupper(bin2hex(random_bytes(6)));
        $payment = $pdo->prepare("INSERT INTO payments (order_id, provider, status, amount_iqd, reference_code, processed_at)
                                  VALUES (:order_id, 'simulation', 'succeeded', :amount, :reference, CURRENT_TIMESTAMP)");
        $payment->execute(['order_id' => $orderId, 'amount' => $subtotal, 'reference' => $reference]);
        $paid = $pdo->prepare("UPDATE orders SET status = 'paid' WHERE id = :id AND status = 'pending'");
        $paid->execute(['id' => $orderId]);
        $clearCart = $pdo->prepare('DELETE FROM cart_items WHERE user_id = :user_id');
        $clearCart->execute(['user_id' => $userId]);
        record_activity($pdo, $userId, 'order.create', 'order', $orderId, ['total_iqd' => $subtotal, 'payment' => 'simulation']);
        $pdo->commit();
        set_flash('success', tr('order_placed'));
        redirect_to(app_url('orders/show.php?id=' . $orderId));
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('error', $exception->getMessage());
        redirect_to(app_url('cart/index.php'));
    } catch (PDOException|JsonException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('error', tr('error_generic'));
        redirect_to(app_url('checkout.php'));
    }
}

try {
    $pdo = db();
    $addressQuery = $pdo->prepare('SELECT id, label, recipient_name, line1, city, country_code, is_default FROM addresses WHERE user_id = :user_id ORDER BY is_default DESC, id DESC');
    $addressQuery->execute(['user_id' => $userId]);
    $addresses = $addressQuery->fetchAll();
    $cartQuery = $pdo->prepare('SELECT ci.quantity, b.title, b.sale_price_iqd FROM cart_items ci JOIN books b ON b.id = ci.book_id WHERE ci.user_id = :user_id AND b.is_active = 1');
    $cartQuery->execute(['user_id' => $userId]);
    $items = $cartQuery->fetchAll();
    foreach ($items as $item) {
        $total += (int) $item['quantity'] * (int) $item['sale_price_iqd'];
    }
} catch (PDOException) {
    $databaseUnavailable = true;
    http_response_code(503);
}

render_header(tr('checkout'));
?>
<section class="page-wrap page-heading">
    <nav class="breadcrumbs" aria-label="<?= escape_html(tr('breadcrumb')) ?>"><a href="<?= escape_html(app_url()) ?>">Libris</a><span aria-hidden="true">/</span><a href="<?= escape_html(app_url('cart/index.php')) ?>"><?= escape_html(tr('your_bag')) ?></a><span aria-hidden="true">/</span><span><?= escape_html(tr('checkout')) ?></span></nav>
    <p class="section-kicker"><?= escape_html(tr('simulated_payment')) ?></p>
    <h1 class="page-title"><?= escape_html(tr('checkout')) ?></h1>
</section>
<section class="page-wrap checkout-layout">
    <?php if ($databaseUnavailable): ?>
        <div class="empty-state"><strong><?= escape_html(tr('curated_shelf')) ?></strong><?= escape_html(tr('nothing_here')) ?></div>
    <?php elseif ($items === []): ?>
        <div class="empty-state"><strong><?= escape_html(tr('empty_bag')) ?></strong><a class="text-link" href="<?= escape_html(app_url('books/index.php')) ?>"><?= escape_html(tr('browse')) ?><span aria-hidden="true">→</span></a></div>
    <?php elseif ($addresses === []): ?>
        <div class="empty-state"><strong><?= escape_html(tr('add_address')) ?></strong><p><?= escape_html(tr('required_fields')) ?></p><a class="button button-ink" href="<?= escape_html(app_url('account/addresses.php')) ?>"><?= escape_html(tr('add_address')) ?></a></div>
    <?php else: ?>
        <form class="checkout-form form-card" action="<?= escape_html(app_url('checkout.php')) ?>" method="post">
            <?= csrf_field() ?>
            <h2 class="serif"><?= escape_html(tr('address')) ?></h2>
            <?php foreach ($addresses as $index => $address): ?>
                <label class="address-choice">
                    <input type="radio" name="address_id" value="<?= (int) $address['id'] ?>"<?= $index === 0 ? ' checked' : '' ?> required>
                    <span><strong><?= escape_html($address['label']) ?><?= (int) $address['is_default'] ? ' · ' . escape_html(tr('set_default')) : '' ?></strong><br><?= escape_html($address['recipient_name']) ?><br><?= escape_html($address['line1']) ?> · <?= escape_html($address['city']) ?> · <?= escape_html($address['country_code']) ?></span>
                </label>
            <?php endforeach; ?>
            <p class="form-help"><?= escape_html(tr('simulated_payment')) ?></p>
            <button class="button button-ink" type="submit"><?= escape_html(tr('place_order')) ?></button>
        </form>
        <aside class="cart-summary checkout-summary">
            <h2 class="serif"><?= escape_html(tr('your_bag')) ?></h2>
            <?php foreach ($items as $item): ?><p class="checkout-line"><span><?= escape_html($item['title']) ?> × <?= (int) $item['quantity'] ?></span><strong><?= escape_html(format_iqd((int) $item['sale_price_iqd'] * (int) $item['quantity'])) ?></strong></p><?php endforeach; ?>
            <div class="checkout-total"><span><?= escape_html(tr('subtotal')) ?></span><strong><?= escape_html(format_iqd($total)) ?></strong></div>
        </aside>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
