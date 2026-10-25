<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_authentication();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/library.php';
$pdo = db();
$userId = (int) current_user()['id'];
$dailyFine = setting_int($pdo, 'late_fine_per_day_iqd', 1000, 0, 100000000);
$statement = $pdo->prepare(
    'SELECT l.id, l.borrowed_at, l.due_at, l.returned_at, l.status, b.id AS book_id, b.title, b.cover_url, b.open_library_id, b.isbn13,
            f.amount_iqd AS assessed_fine, f.status AS fine_status,
            GREATEST(DATEDIFF(CURDATE(), l.due_at), 0) AS overdue_days
     FROM loans l
     JOIN book_copies c ON c.id = l.copy_id
     JOIN books b ON b.id = c.book_id
     LEFT JOIN fines f ON f.loan_id = l.id
     WHERE l.user_id = :user_id
     ORDER BY (l.status = \'active\') DESC, l.due_at DESC'
);
$statement->execute(['user_id' => $userId]);
$loans = $statement->fetchAll();
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('my_loans'), 'account');
?>
<section class="page-wrap account-layout">
    <nav class="account-nav" aria-label="<?= escape_html(tr('account')) ?>">
        <a href="<?= escape_html(app_url('account/index.php')) ?>"><?= escape_html(tr('account_overview')) ?></a>
        <a class="is-active" href="<?= escape_html(app_url('account/loans.php')) ?>"><?= escape_html(tr('my_loans')) ?></a>
        <a href="<?= escape_html(app_url('account/reservations.php')) ?>"><?= escape_html(tr('reservations')) ?></a>
        <a href="<?= escape_html(app_url('account/wishlist.php')) ?>"><?= escape_html(tr('wishlist')) ?></a>
        <a href="<?= escape_html(app_url('account/addresses.php')) ?>"><?= escape_html(tr('profile')) ?></a>
    </nav>
    <div class="account-content">
        <p class="eyebrow"><?= escape_html(tr('account_eyebrow')) ?></p>
        <h1><?= escape_html(tr('my_loans')) ?></h1>
        <?php if ($loans === []): ?>
            <section class="account-card"><p><?= escape_html(tr('empty_loans')) ?></p><a class="button button-outline" href="<?= escape_html(app_url('books/index.php?availability=loan')) ?>"><?= escape_html(tr('browse')) ?></a></section>
        <?php else: ?>
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th><?= escape_html(tr('book')) ?></th><th><?= escape_html(tr('borrowed_at')) ?></th><th><?= escape_html(tr('due_date')) ?></th><th><?= escape_html(tr('loan_status')) ?></th><th><?= escape_html(tr('late_fine')) ?></th></tr></thead>
                <tbody><?php foreach ($loans as $loan):
                    $currentFine = (int) ($loan['assessed_fine'] ?? calculate_late_fine((int) $loan['overdue_days'], $dailyFine));
                    $fineStateKey = match ($loan['fine_status'] ?? null) {
                        'unpaid' => 'fine_state_unpaid',
                        'paid' => 'fine_state_paid',
                        'waived' => 'fine_state_waived',
                        null => (int) $loan['overdue_days'] > 0 ? 'fine_state_estimated' : null,
                        default => null,
                    };
                    ?><tr>
                        <td><a href="<?= escape_html(app_url('books/show.php?id=' . (int) $loan['book_id'])) ?>"><?= escape_html($loan['title']) ?></a></td>
                        <td><?= escape_html($loan['borrowed_at']) ?></td>
                        <td><?= escape_html($loan['due_at']) ?></td>
                        <td><span class="status-pill"><?= escape_html(tr('status_' . $loan['status'])) ?></span></td>
                            <td>
                                <?php if ($fineStateKey === null): ?>—<?php else: ?>
                                    <?= escape_html(format_iqd($currentFine)) ?>
                                    <small><?= escape_html(tr($fineStateKey)) ?></small>
                                <?php endif; ?>
                            </td>
                    </tr><?php endforeach; ?></tbody>
            </table></div>
            <p class="form-help"><?= escape_html(tr('fine_policy')) ?> <?= escape_html(format_iqd($dailyFine)) ?></p>
        <?php endif; ?>
    </div>
</section>
<?php render_footer(); ?>
