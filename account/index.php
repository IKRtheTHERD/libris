<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_authentication();
require_once __DIR__ . '/../includes/db.php';
$pdo = db();
$user = current_user();
$userId = (int) $user['id'];
$counts = [];
foreach ([
    'orders' => ['SELECT COUNT(*) FROM orders WHERE user_id = :user_id', ['user_id' => $userId]],
    'active_loans' => ["SELECT COUNT(*) FROM loans WHERE user_id = :user_id AND status = 'active'", ['user_id' => $userId]],
    'reservations' => ["SELECT COUNT(*) FROM reservations WHERE user_id = :user_id AND status IN ('waiting','ready')", ['user_id' => $userId]],
    'wishlist' => ['SELECT COUNT(*) FROM wishlist WHERE user_id = :user_id', ['user_id' => $userId]],
] as $key => [$sql, $parameters]) {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $counts[$key] = (int) $statement->fetchColumn();
}

require_once __DIR__ . '/../includes/layout.php';
render_header(tr('account'), 'account');
?>
<section class="page-wrap account-layout">
    <nav class="account-nav" aria-label="<?= escape_html(tr('account')) ?>">
        <a class="is-active" href="<?= escape_html(app_url('account/index.php')) ?>"><?= escape_html(tr('account_overview')) ?></a>
        <a href="<?= escape_html(app_url('account/addresses.php')) ?>"><?= escape_html(tr('profile')) ?></a>
        <a href="<?= escape_html(app_url('orders/index.php')) ?>"><?= escape_html(tr('orders')) ?></a>
        <a href="<?= escape_html(app_url('account/loans.php')) ?>"><?= escape_html(tr('my_loans')) ?></a>
        <a href="<?= escape_html(app_url('account/reservations.php')) ?>"><?= escape_html(tr('reservations')) ?></a>
        <a href="<?= escape_html(app_url('account/wishlist.php')) ?>"><?= escape_html(tr('wishlist')) ?></a>
    </nav>
    <div class="account-content">
        <p class="eyebrow"><?= escape_html(tr('account_eyebrow')) ?></p>
        <h1><?= escape_html(tr('welcome')) ?>, <?= escape_html($user['name']) ?></h1>
        <p class="form-intro"><?= escape_html($user['email']) ?></p>
        <div class="stats-grid">
            <?php foreach ([['orders', 'orders'], ['active_loans', 'active_loans'], ['reservations', 'reservations'], ['wishlist', 'wishlist']] as [$key, $label]): ?>
                <article class="stat-card"><span><?= escape_html(tr($label)) ?></span><strong><?= number_format($counts[$key]) ?></strong></article>
            <?php endforeach; ?>
        </div>
        <section class="account-card">
            <h2><?= escape_html(tr('your_shelves')) ?></h2>
            <p><?= escape_html(tr('account_intro')) ?></p>
            <a class="button button-outline" href="<?= escape_html(app_url('books/index.php')) ?>"><?= escape_html(tr('browse')) ?></a>
        </section>
    </div>
</section>
<?php render_footer(); ?>
