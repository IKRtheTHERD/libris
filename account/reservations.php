<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_authentication();
require_once __DIR__ . '/../includes/db.php';
$statement = db()->prepare(
    'SELECT r.id, r.status, r.queue_position, r.created_at, r.ready_until, b.id AS book_id, b.title
     FROM reservations r
     JOIN books b ON b.id = r.book_id
     WHERE r.user_id = :user_id
     ORDER BY FIELD(r.status, \'ready\', \'waiting\', \'fulfilled\', \'cancelled\', \'expired\'), r.created_at DESC'
);
$statement->execute(['user_id' => (int) current_user()['id']]);
$reservations = $statement->fetchAll();
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('reservations'), 'account');
?>
<section class="page-wrap account-layout">
    <nav class="account-nav" aria-label="<?= escape_html(tr('account')) ?>">
        <a href="<?= escape_html(app_url('account/index.php')) ?>"><?= escape_html(tr('account_overview')) ?></a>
        <a href="<?= escape_html(app_url('account/loans.php')) ?>"><?= escape_html(tr('my_loans')) ?></a>
        <a class="is-active" href="<?= escape_html(app_url('account/reservations.php')) ?>"><?= escape_html(tr('reservations')) ?></a>
        <a href="<?= escape_html(app_url('account/wishlist.php')) ?>"><?= escape_html(tr('wishlist')) ?></a>
        <a href="<?= escape_html(app_url('account/addresses.php')) ?>"><?= escape_html(tr('profile')) ?></a>
    </nav>
    <div class="account-content">
        <p class="eyebrow"><?= escape_html(tr('account_eyebrow')) ?></p>
        <h1><?= escape_html(tr('reservations')) ?></h1>
        <?php if ($reservations === []): ?>
            <section class="account-card"><p><?= escape_html(tr('empty_reservations')) ?></p><a class="button button-outline" href="<?= escape_html(app_url('books/index.php?availability=loan')) ?>"><?= escape_html(tr('browse')) ?></a></section>
        <?php else: ?>
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th><?= escape_html(tr('book')) ?></th><th><?= escape_html(tr('reservation_status')) ?></th><th><?= escape_html(tr('queue_position')) ?></th><th><?= escape_html(tr('ready_until')) ?></th><th><?= escape_html(tr('reserved_at')) ?></th><th><?= escape_html(tr('actions')) ?></th></tr></thead>
                <tbody><?php foreach ($reservations as $reservation): ?>
                    <tr>
                        <td><a href="<?= escape_html(app_url('books/show.php?id=' . (int) $reservation['book_id'])) ?>"><?= escape_html($reservation['title']) ?></a></td>
                        <td><span class="status-pill is-<?= escape_html($reservation['status']) ?>"><?= escape_html(tr('status_' . $reservation['status'])) ?></span></td>
                        <td><?= $reservation['queue_position'] === null ? '—' : '#' . (int) $reservation['queue_position'] ?></td>
                        <td><?= escape_html($reservation['ready_until'] ?? '—') ?></td>
                        <td><?= escape_html($reservation['created_at']) ?></td>
                        <td>
                            <?php if ($reservation['status'] === 'waiting'): ?>
                                <form action="<?= escape_html(app_url('account/cancel_reservation.php')) ?>" method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                    <button class="button button-text" type="submit"><?= escape_html(tr('cancel_reservation')) ?></button>
                                </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </div>
</section>
<?php render_footer(); ?>
