<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_authentication();
require_once __DIR__ . '/../includes/db.php';
$statement = db()->prepare('SELECT id, order_number, status, total_iqd, currency, created_at FROM orders WHERE user_id = :user_id ORDER BY created_at DESC, id DESC');
$statement->execute(['user_id' => (int) current_user()['id']]);
$orders = $statement->fetchAll();
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('orders'), 'account');
?>
<section class="page-wrap account-layout">
    <nav class="account-nav" aria-label="<?= escape_html(tr('account')) ?>">
        <a href="<?= escape_html(app_url('account/index.php')) ?>"><?= escape_html(tr('account_overview')) ?></a>
        <a class="is-active" href="<?= escape_html(app_url('orders/index.php')) ?>"><?= escape_html(tr('orders')) ?></a>
        <a href="<?= escape_html(app_url('account/loans.php')) ?>"><?= escape_html(tr('my_loans')) ?></a>
        <a href="<?= escape_html(app_url('account/reservations.php')) ?>"><?= escape_html(tr('reservations')) ?></a>
        <a href="<?= escape_html(app_url('account/profile.php')) ?>"><?= escape_html(tr('profile')) ?></a>
    </nav>
    <div class="account-content">
        <p class="eyebrow"><?= escape_html(tr('account_eyebrow')) ?></p>
        <h1><?= escape_html(tr('orders')) ?></h1>
        <?php if ($orders === []): ?>
            <section class="account-card"><p><?= escape_html(tr('empty_orders')) ?></p><a class="button button-outline" href="<?= escape_html(app_url('books/index.php?availability=sale')) ?>"><?= escape_html(tr('browse')) ?></a></section>
        <?php else: ?>
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th><?= escape_html(tr('order_number')) ?></th><th><?= escape_html(tr('order_date')) ?></th><th><?= escape_html(tr('order_status')) ?></th><th><?= escape_html(tr('order_total')) ?></th><th><?= escape_html(tr('details')) ?></th></tr></thead>
                <tbody><?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= escape_html($order['order_number']) ?></td><td><?= escape_html($order['created_at']) ?></td>
                        <td><span class="status-pill is-<?= escape_html($order['status']) ?>"><?= escape_html(tr('status_' . $order['status'])) ?></span></td>
                        <td><?= escape_html(format_iqd((int) $order['total_iqd'])) ?></td>
                        <td><a href="<?= escape_html(app_url('orders/show.php?id=' . (int) $order['id'])) ?>"><?= escape_html(tr('view_order')) ?></a></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </div>
</section>
<?php render_footer(); ?>
