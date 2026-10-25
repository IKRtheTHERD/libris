<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_staff();
require_once __DIR__ . '/../includes/db.php';
$pdo = db();
$counts = [
    'total_books' => (int) $pdo->query('SELECT COUNT(*) FROM books WHERE is_active = 1')->fetchColumn(),
    'open_orders' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('paid','processing','ready')")->fetchColumn(),
    'active_loans' => (int) $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'active'")->fetchColumn(),
    'members' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND is_active = 1")->fetchColumn(),
];
$activity = $pdo->query(
    'SELECT a.action, a.entity_type, a.created_at, u.first_name, u.last_name
     FROM activity_log a LEFT JOIN users u ON u.id = a.actor_user_id
     ORDER BY a.created_at DESC LIMIT 12'
)->fetchAll();
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('staff'), 'staff');
?>
<section class="page-wrap admin-shell">
    <p class="eyebrow"><?= escape_html(tr('account_eyebrow')) ?></p>
    <h1><?= escape_html(tr('staff')) ?></h1>
    <p class="form-intro"><?= escape_html(tr('admin_subtitle')) ?></p>
    <nav class="admin-nav" aria-label="<?= escape_html(tr('staff')) ?>">
        <?php foreach ([['books.php','manage_books'],['orders.php','manage_orders'],['loans.php','manage_loans'],['reservations.php','manage_reservations'],['users.php','manage_users'],['reports.php','view_reports'],['settings.php','manage_settings']] as [$path,$label]): ?>
            <a href="<?= escape_html(app_url('admin/' . $path)) ?>"><?= escape_html(tr($label)) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="stats-grid">
        <?php foreach ($counts as $key => $value): ?><article class="stat-card"><span><?= escape_html(tr($key)) ?></span><strong><?= number_format($value) ?></strong></article><?php endforeach; ?>
    </div>
    <section class="account-card">
        <h2><?= escape_html(tr('recent_activity')) ?></h2>
        <?php if ($activity === []): ?><p><?= escape_html(tr('no_activity')) ?></p><?php else: ?>
            <div class="table-wrap"><table class="data-table"><thead><tr><th><?= escape_html(tr('member')) ?></th><th><?= escape_html(tr('activity')) ?></th><th><?= escape_html(tr('type')) ?></th><th><?= escape_html(tr('date')) ?></th></tr></thead>
                <tbody><?php foreach ($activity as $event): ?><tr>
                    <td><?= escape_html(trim(($event['first_name'] ?? '') . ' ' . ($event['last_name'] ?? '')) ?: tr('system')) ?></td>
                    <td><?= escape_html($event['action']) ?></td><td><?= escape_html($event['entity_type'] ?? '—') ?></td><td><?= escape_html($event['created_at']) ?></td>
                </tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
</section>
<?php render_footer(); ?>
