<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_authentication();
require_once __DIR__ . '/../includes/db.php';
$orderId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($orderId === false || $orderId < 1) {
    http_response_code(404);
    exit(tr('not_found'));
}
$pdo = db();
$orderQuery = $pdo->prepare('SELECT id, order_number, status, subtotal_iqd, total_iqd, created_at, shipping_address_snapshot FROM orders WHERE id = :id AND user_id = :user_id');
$orderQuery->execute(['id' => $orderId, 'user_id' => (int) current_user()['id']]);
$order = $orderQuery->fetch();
if (!$order) {
    http_response_code(404);
    exit(tr('not_found'));
}
$itemsQuery = $pdo->prepare('SELECT title_snapshot, unit_price_iqd, quantity FROM order_items WHERE order_id = :order_id ORDER BY id');
$itemsQuery->execute(['order_id' => $orderId]);
$items = $itemsQuery->fetchAll();
$shippingAddress = json_decode((string) ($order['shipping_address_snapshot'] ?? ''), true);
if (!is_array($shippingAddress)) {
    $shippingAddress = null;
}
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('order_details'), 'account');
?>
<section class="page-wrap account-layout">
    <nav class="account-nav" aria-label="<?= escape_html(tr('account')) ?>">
        <a href="<?= escape_html(app_url('account/index.php')) ?>"><?= escape_html(tr('account_overview')) ?></a>
        <a class="is-active" href="<?= escape_html(app_url('orders/index.php')) ?>"><?= escape_html(tr('orders')) ?></a>
        <a href="<?= escape_html(app_url('account/loans.php')) ?>"><?= escape_html(tr('my_loans')) ?></a>
        <a href="<?= escape_html(app_url('account/profile.php')) ?>"><?= escape_html(tr('profile')) ?></a>
    </nav>
    <div class="account-content">
        <p class="eyebrow"><?= escape_html(tr('order_number')) ?> · <?= escape_html($order['order_number']) ?></p>
        <h1><?= escape_html(tr('order_details')) ?></h1>
        <p><span class="status-pill is-<?= escape_html($order['status']) ?>"><?= escape_html(tr('status_' . $order['status'])) ?></span> · <?= escape_html($order['created_at']) ?></p>
        <section class="account-card">
            <h2><?= escape_html(tr('order_items')) ?></h2>
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th><?= escape_html(tr('book')) ?></th><th><?= escape_html(tr('quantity')) ?></th><th><?= escape_html(tr('price')) ?></th><th><?= escape_html(tr('order_total')) ?></th></tr></thead>
                <tbody><?php foreach ($items as $item): ?><tr>
                    <td><?= escape_html($item['title_snapshot']) ?></td><td><?= (int) $item['quantity'] ?></td>
                    <td><?= escape_html(format_iqd((int) $item['unit_price_iqd'])) ?></td>
                    <td><?= escape_html(format_iqd((int) $item['unit_price_iqd'] * (int) $item['quantity'])) ?></td>
                </tr><?php endforeach; ?></tbody>
                <tfoot><tr><th colspan="3"><?= escape_html(tr('order_total')) ?></th><th><?= escape_html(format_iqd((int) $order['total_iqd'])) ?></th></tr></tfoot>
            </table></div>
        </section>
        <?php if ($shippingAddress !== null): ?>
            <section class="account-card"><h2><?= escape_html(tr('shipping_address')) ?></h2>
                <p><?= escape_html($shippingAddress['recipient_name'] ?? '') ?><br><?= escape_html($shippingAddress['line1'] ?? '') ?><?php if (!empty($shippingAddress['line2'])): ?><br><?= escape_html($shippingAddress['line2']) ?><?php endif; ?><br><?= escape_html($shippingAddress['city'] ?? '') ?> <?= escape_html($shippingAddress['region'] ?? '') ?> <?= escape_html($shippingAddress['postal_code'] ?? '') ?><br><?= escape_html($shippingAddress['country_code'] ?? '') ?></p>
            </section>
        <?php endif; ?>
        <p class="form-help"><?= escape_html(tr('simulated_payment')) ?></p>
        <a class="button button-outline" href="<?= escape_html(app_url('orders/index.php')) ?>"><?= escape_html(tr('back_to_orders')) ?></a>
    </div>
</section>
<?php render_footer(); ?>
