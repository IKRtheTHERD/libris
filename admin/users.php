<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_authentication();
if ((current_user()['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/user_management.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    try {
        if (!is_int($userId) || $userId < 1 || $userId === (int) current_user()['id'] || !in_array($action, ['activate', 'deactivate'], true)) {
            throw new InvalidArgumentException(tr('user_change_denied'));
        }
        set_customer_account_active(
            $pdo,
            (int) current_user()['id'],
            $userId,
            $action === 'activate'
        );
        set_flash('success', tr('changes_saved'));
    } catch (InvalidArgumentException|DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', tr('user_change_denied'));
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        set_flash('error', tr('error_generic'));
    }
    redirect_to(app_url('admin/users.php'));
}
$users = $pdo->query(
    "SELECT u.id, u.first_name, u.last_name, u.email, u.is_active, u.created_at,
            (SELECT COUNT(*) FROM loans l WHERE l.user_id = u.id AND l.status = 'active') AS active_loans
     FROM users u WHERE u.role = 'customer' ORDER BY u.created_at DESC LIMIT 250"
)->fetchAll();
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('manage_users'), 'staff');
?>
<section class="page-wrap admin-shell">
    <p class="eyebrow"><?= escape_html(tr('staff')) ?></p><h1><?= escape_html(tr('manage_users')) ?></h1>
    <p class="form-help"><?= escape_html(tr('member_access_note')) ?></p>
    <?php if ($users === []): ?><section class="account-card"><p><?= escape_html(tr('no_members')) ?></p></section><?php else: ?>
        <div class="table-wrap"><table class="data-table"><thead><tr><th><?= escape_html(tr('member')) ?></th><th><?= escape_html(tr('email')) ?></th><th><?= escape_html(tr('active_loans')) ?></th><th><?= escape_html(tr('date')) ?></th><th><?= escape_html(tr('status')) ?></th><th><?= escape_html(tr('actions')) ?></th></tr></thead>
            <tbody><?php foreach ($users as $member): ?><tr>
                <td><?= escape_html($member['first_name'] . ' ' . $member['last_name']) ?></td><td><?= escape_html($member['email']) ?></td><td><?= (int) $member['active_loans'] ?></td><td><?= escape_html($member['created_at']) ?></td>
                <td><span class="status-pill is-<?= (bool) $member['is_active'] ? 'active' : 'cancelled' ?>"><?= escape_html(tr((bool) $member['is_active'] ? 'active' : 'inactive')) ?></span></td>
                <td><form method="post" action="<?= escape_html(app_url('admin/users.php')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>"><input type="hidden" name="action" value="<?= (bool) $member['is_active'] ? 'deactivate' : 'activate' ?>">
                    <button class="button button-small" type="submit"><?= escape_html(tr((bool) $member['is_active'] ? 'deactivate' : 'activate')) ?></button>
                </form></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
