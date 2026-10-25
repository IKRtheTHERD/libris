<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_staff();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/library_operations.php';

$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();
    $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
    $expectedStatus = is_string($_POST['expected_status'] ?? null) ? $_POST['expected_status'] : '';
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    try {
        if (!is_int($reservationId) || $reservationId < 1) {
            throw new InvalidArgumentException(tr('error_generic'));
        }
        $result = operate_staff_reservation(
            $pdo,
            $reservationId,
            $expectedStatus,
            $action,
            (int) current_user()['id']
        );
        $message = match ($result['action']) {
            'prepared' => tr('reservation_prepared'),
            'expired' => tr('reservation_expired'),
            default => tr('reservation_cancelled'),
        };
        set_flash('success', $message);
    } catch (DomainException $exception) {
        set_flash('error', $exception->getMessage());
    } catch (InvalidArgumentException) {
        set_flash('error', tr('error_generic'));
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        set_flash('error', tr('error_generic'));
    }
    redirect_to(app_url('admin/reservations.php'));
}

$pageSize = 50;
$totalReservations = (int) $pdo->query(
    "SELECT COUNT(*) FROM reservations WHERE status IN ('waiting', 'ready')"
)->fetchColumn();
$pageCount = max(1, (int) ceil($totalReservations / $pageSize));
$page = clamp_int($_GET['page'] ?? 1, 1, $pageCount, 1);
$offset = ($page - 1) * $pageSize;
$reservationQuery = $pdo->prepare(
    "SELECT r.id, r.book_id, r.user_id, r.status, r.queue_position, r.created_at, r.ready_until,
            CASE WHEN r.status = 'ready' AND r.ready_until < CURRENT_DATE THEN 1 ELSE 0 END AS is_expired,
            b.title, u.first_name, u.last_name, u.email
     FROM reservations r
     JOIN books b ON b.id = r.book_id
     JOIN users u ON u.id = r.user_id
     WHERE r.status IN ('waiting', 'ready')
     ORDER BY CASE r.status WHEN 'ready' THEN 0 ELSE 1 END,
              r.ready_until, r.queue_position, r.created_at, r.id
     LIMIT :limit OFFSET :offset"
);
$reservationQuery->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$reservationQuery->bindValue(':offset', $offset, PDO::PARAM_INT);
$reservationQuery->execute();
$reservations = $reservationQuery->fetchAll(PDO::FETCH_ASSOC);
$availableCopies = $pdo->query(
    "SELECT book_id, COUNT(*) FROM book_copies
     WHERE purpose = 'loan' AND status = 'available' GROUP BY book_id"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$firstWaitingByBook = [];
$waitingEntries = $pdo->query(
    "SELECT id, book_id FROM reservations WHERE status = 'waiting' ORDER BY created_at, id"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($waitingEntries as $waitingEntry) {
    $bookId = (int) $waitingEntry['book_id'];
    $firstWaitingByBook[$bookId] ??= (int) $waitingEntry['id'];
}
$success = take_flash('success');
$error = take_flash('error');
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('manage_reservations'), 'staff');
?>
<section class="page-wrap admin-shell">
    <p class="eyebrow"><a href="<?= escape_html(app_url('admin/loans.php')) ?>"><?= escape_html(tr('manage_loans')) ?></a></p>
    <div class="section-heading">
        <div>
            <h1><?= escape_html(tr('manage_reservations')) ?></h1>
            <p class="form-intro"><?= escape_html(tr('reservation_status')) ?> · <?= escape_html(tr('queue_position')) ?></p>
        </div>
    </div>
    <?php if ($success !== null): ?><p class="flash flash-success" role="status"><?= escape_html($success) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="form-error" role="alert"><?= escape_html($error) ?></p><?php endif; ?>

    <?php if ($reservations === []): ?>
        <section class="account-card"><p><?= escape_html(tr('no_open_reservations')) ?></p></section>
    <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr>
                <th scope="col"><?= escape_html(tr('book')) ?></th>
                <th scope="col"><?= escape_html(tr('member')) ?></th>
                <th scope="col"><?= escape_html(tr('reservation_status')) ?></th>
                <th scope="col"><?= escape_html(tr('queue_position')) ?></th>
                <th scope="col"><?= escape_html(tr('ready_until')) ?></th>
                <th scope="col"><?= escape_html(tr('reserved_at')) ?></th>
                <th scope="col"><?= escape_html(tr('actions')) ?></th>
            </tr></thead>
            <tbody><?php foreach ($reservations as $reservation):
                $status = (string) $reservation['status'];
                $bookId = (int) $reservation['book_id'];
                $canPrepare = $status === 'waiting'
                    && ($firstWaitingByBook[$bookId] ?? null) === (int) $reservation['id']
                    && (int) ($availableCopies[$bookId] ?? 0) > 0;
            ?>
                <tr>
                    <td><?= escape_html($reservation['title']) ?></td>
                    <td><?= escape_html(trim($reservation['first_name'] . ' ' . $reservation['last_name'])) ?><br><small><?= escape_html($reservation['email']) ?></small></td>
                    <td><span class="status-pill is-<?= escape_html($status) ?>"><?= escape_html(tr('status_' . $status)) ?></span></td>
                    <td><?= $reservation['queue_position'] === null ? '—' : '#' . (int) $reservation['queue_position'] ?></td>
                    <td><?= $reservation['ready_until'] === null ? '—' : escape_html($reservation['ready_until']) ?></td>
                    <td><?= escape_html($reservation['created_at']) ?></td>
                    <td class="admin-actions">
                        <?php if ($canPrepare): ?>
                            <form method="post" action="<?= escape_html(app_url('admin/reservations.php')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                <input type="hidden" name="expected_status" value="waiting">
                                <input type="hidden" name="action" value="prepare">
                                <button class="button button-small" type="submit"><?= escape_html(tr('prepare_hold')) ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ($status === 'ready' && (int) $reservation['is_expired'] === 1): ?>
                            <form method="post" action="<?= escape_html(app_url('admin/reservations.php')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                <input type="hidden" name="expected_status" value="ready">
                                <input type="hidden" name="action" value="expire">
                                <button class="button button-small" type="submit"><?= escape_html(tr('expire_hold')) ?></button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= escape_html(app_url('admin/reservations.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                            <input type="hidden" name="expected_status" value="<?= escape_html($status) ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button class="button button-small button-outline" type="submit"><?= escape_html(tr('cancel_reservation')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php if ($pageCount > 1): ?>
            <nav class="pagination" aria-label="<?= escape_html(tr('pagination')) ?>">
                <?php if ($page > 1): ?>
                    <a href="<?= escape_html(app_url('admin/reservations.php?' . http_build_query(['page' => $page - 1]))) ?>"><?= escape_html(tr('previous')) ?></a>
                <?php endif; ?>
                <span><?= escape_html(tr('page')) ?> <?= $page ?> <?= escape_html(tr('of')) ?> <?= $pageCount ?></span>
                <?php if ($page < $pageCount): ?>
                    <a href="<?= escape_html(app_url('admin/reservations.php?' . http_build_query(['page' => $page + 1]))) ?>"><?= escape_html(tr('next')) ?></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
