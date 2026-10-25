<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_staff();
require_once __DIR__ . '/../includes/db.php';
$pdo = db();
$summary = [
    'total_books' => (int) $pdo->query('SELECT COUNT(*) FROM books WHERE is_active = 1')->fetchColumn(),
    'copies' => (int) $pdo->query('SELECT COUNT(*) FROM book_copies')->fetchColumn(),
    'active_loans' => (int) $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'active'")->fetchColumn(),
    'overdue' => (int) $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'active' AND due_at < CURDATE()")->fetchColumn(),
];
$sales = $pdo->query(
    "SELECT COUNT(*) AS order_count, COALESCE(SUM(total_iqd), 0) AS revenue_iqd
     FROM orders WHERE status IN ('paid','processing','ready','completed')
       AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
)->fetch();
$monthlySales = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS sales_month,
            COUNT(*) AS order_count, COALESCE(SUM(total_iqd), 0) AS revenue_iqd
     FROM orders
     WHERE status IN ('paid','processing','ready','completed')
       AND created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY sales_month"
)->fetchAll();
$maxRevenue = max(1, ...array_map(static fn (array $row): int => (int) $row['revenue_iqd'], $monthlySales));
$inventory = $pdo->query('SELECT purpose, status, COUNT(*) AS copy_count FROM book_copies GROUP BY purpose, status ORDER BY purpose, status')->fetchAll();
$topBooks = $pdo->query(
    "SELECT oi.title_snapshot, SUM(oi.quantity) AS units_sold, SUM(oi.quantity * oi.unit_price_iqd) AS revenue_iqd
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.status IN ('paid','processing','ready','completed')
     GROUP BY oi.book_id, oi.title_snapshot ORDER BY units_sold DESC, revenue_iqd DESC LIMIT 10"
)->fetchAll();
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('view_reports'), 'staff');
?>
<section class="page-wrap admin-shell">
    <p class="eyebrow"><?= escape_html(tr('staff')) ?></p><h1><?= escape_html(tr('view_reports')) ?></h1>
    <p class="form-intro"><?= escape_html(tr('reporting_period')) ?>: <?= escape_html(date('Y-m')) ?></p>
    <div class="stats-grid">
        <?php foreach ($summary as $key => $value): ?><article class="stat-card"><span><?= escape_html(tr($key)) ?></span><strong><?= number_format($value) ?></strong></article><?php endforeach; ?>
    </div>
    <div class="admin-form">
        <section class="account-card"><h2><?= escape_html(tr('month_sales')) ?></h2><p><?= (int) $sales['order_count'] ?> <?= escape_html(tr('orders')) ?></p><strong class="report-total"><?= escape_html(format_iqd((int) $sales['revenue_iqd'])) ?></strong></section>
<section class="account-card sales-trend" aria-labelledby="monthly-sales-heading"><h2 id="monthly-sales-heading"><?= escape_html(tr('monthly_sales_trend')) ?></h2>
<?php if ($monthlySales === []): ?><p><?= escape_html(tr('no_sales')) ?></p><?php else: ?>
<ul class="report-bars" aria-label="<?= escape_html(tr('monthly_sales_trend')) ?>">
<?php foreach ($monthlySales as $month): $monthLabel = date('Y-m', strtotime($month['sales_month'] . '-01')); $revenue = (int) $month['revenue_iqd']; $orderCount = (int) $month['order_count']; ?>
<li><div class="report-bar-label"><span><?= escape_html($monthLabel) ?></span><span><?= escape_html(format_iqd($revenue)) ?> · <?= $orderCount ?> <?= escape_html(tr('orders')) ?></span></div><progress max="<?= $maxRevenue ?>" value="<?= $revenue ?>" aria-label="<?= escape_html($monthLabel . ': ' . format_iqd($revenue) . ', ' . $orderCount . ' ' . tr('orders')) ?>"></progress></li>
<?php endforeach; ?></ul>
<div class="table-wrap" role="region" tabindex="0" aria-label="<?= escape_html(tr('monthly_sales_trend')) ?>">
    <table class="data-table">
        <caption class="sr-only"><?= escape_html(tr('monthly_sales_trend')) ?></caption>
        <thead><tr><th scope="col"><?= escape_html(tr('reporting_period')) ?></th><th scope="col"><?= escape_html(tr('order_total')) ?></th><th scope="col"><?= escape_html(tr('orders')) ?></th></tr></thead>
        <tbody><?php foreach ($monthlySales as $month): $monthLabel = date('Y-m', strtotime($month['sales_month'] . '-01')); ?><tr><th scope="row"><time datetime="<?= escape_html($monthLabel) ?>"><?= escape_html($monthLabel) ?></time></th><td><?= escape_html(format_iqd((int) $month['revenue_iqd'])) ?></td><td><?= (int) $month['order_count'] ?></td></tr><?php endforeach; ?></tbody>
    </table>
</div>
<?php endif; ?></section>
        <section class="account-card"><h2><?= escape_html(tr('copy_inventory')) ?></h2>
            <?php if ($inventory === []): ?><p><?= escape_html(tr('no_copies')) ?></p><?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th><?= escape_html(tr('purpose')) ?></th><th><?= escape_html(tr('status')) ?></th><th><?= escape_html(tr('quantity')) ?></th></tr></thead><tbody>
                <?php foreach ($inventory as $row): ?><tr><td><?= escape_html(tr('purpose_' . $row['purpose'])) ?></td><td><?= escape_html(tr('copy_' . $row['status'])) ?></td><td><?= (int) $row['copy_count'] ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
    </div>
    <section class="account-card"><h2><?= escape_html(tr('top_selling')) ?></h2>
        <?php if ($topBooks === []): ?><p><?= escape_html(tr('no_sales')) ?></p><?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th><?= escape_html(tr('book')) ?></th><th><?= escape_html(tr('units_sold')) ?></th><th><?= escape_html(tr('order_total')) ?></th></tr></thead><tbody>
            <?php foreach ($topBooks as $book): ?><tr><td><?= escape_html($book['title_snapshot']) ?></td><td><?= (int) $book['units_sold'] ?></td><td><?= escape_html(format_iqd((int) $book['revenue_iqd'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
</section>
<?php render_footer(); ?>
