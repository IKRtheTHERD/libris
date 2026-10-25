<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_staff();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../orders/staff_workflow.php';
$pdo = db();
$nextStatus = ['paid' => 'processing', 'processing' => 'ready', 'ready' => 'completed'];
$refundableStatuses = ['paid', 'processing', 'ready'];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();
    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
    try {
        if (!is_int($orderId) || $orderId < 1) {
            throw new InvalidArgumentException(tr('error_generic'));
        }
        $action = $_POST['action'] ?? 'advance';
        $requestedStatus = $_POST['next_status'] ?? null;
        if (!is_string($action) || ($requestedStatus !== null && !is_string($requestedStatus))) {
            throw new InvalidArgumentException(tr('error_generic'));
        }
        apply_staff_order_action(
            $pdo,
            $orderId,
            $action,
            $requestedStatus,
            (int) current_user()['id']
        );
        set_flash('success', tr('changes_saved'));
    } catch (InvalidArgumentException|DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        set_flash('error', tr('error_generic'));
    }
    redirect_to(app_url('admin/orders.php'));
}

$orders = $pdo->query(
    'SELECT o.id, o.order_number, o.status, o.total_iqd, o.created_at, u.first_name, u.last_name,
            (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
     FROM orders o JOIN users u ON u.id = o.user_id
     ORDER BY FIELD(o.status, \'paid\', \'processing\', \'ready\', \'pending\', \'completed\', \'cancelled\', \'refunded\'), o.created_at DESC
     LIMIT 200'
)->fetchAll();
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('manage_orders'), 'staff');
?>
<section class="page-wrap admin-shell">
    <p class="eyebrow"><?= escape_html(tr('staff')) ?></p><h1><?= escape_html(tr('manage_orders')) ?></h1>
    <?php if ($orders === []): ?><section class="account-card"><p><?= escape_html(tr('empty_orders')) ?></p></section><?php else: ?>
        <div class="table-wrap"><table class="data-table"><thead><tr><th><?= escape_html(tr('order_number')) ?></th><th><?= escape_html(tr('member')) ?></th><th><?= escape_html(tr('order_date')) ?></th><th><?= escape_html(tr('quantity')) ?></th><th><?= escape_html(tr('order_total')) ?></th><th><?= escape_html(tr('status')) ?></th><th><?= escape_html(tr('actions')) ?></th></tr></thead>
            <tbody><?php foreach ($orders as $order): ?>
                <tr><td><?= escape_html($order['order_number']) ?></td><td><?= escape_html($order['first_name'] . ' ' . $order['last_name']) ?></td><td><?= escape_html($order['created_at']) ?></td>
                    <td><?= (int) $order['item_count'] ?></td><td><?= escape_html(format_iqd((int) $order['total_iqd'])) ?></td>
                    <td><span class="status-pill is-<?= escape_html($order['status']) ?>"><?= escape_html(tr('status_' . $order['status'])) ?></span></td><td>
                    <?php if (isset($nextStatus[$order['status']])): ?><form method="post" action="<?= escape_html(app_url('admin/orders.php')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="advance"><input type="hidden" name="next_status" value="<?= escape_html($nextStatus[$order['status']]) ?>">
                        <button class="button button-small" type="submit"><?= escape_html(tr('advance_order')) ?></button>
                    </form><?php endif; ?>
                    <?php if (in_array($order['status'], $refundableStatuses, true)): ?><form method="post" action="<?= escape_html(app_url('admin/orders.php')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="refund">
                        <button class="button button-small button-outline" type="submit"><?= escape_html(tr('status_refunded')) ?></button>
                    </form><?php endif; ?>
                    <?php if (!isset($nextStatus[$order['status']]) && !in_array($order['status'], $refundableStatuses, true)): ?>—<?php endif; ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
