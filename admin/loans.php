<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_staff();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/library.php';
require_once __DIR__ . '/library_operations.php';

$pdo = db();
$requestedLoanPage = clamp_int($_POST['loan_page'] ?? $_GET['loan_page'] ?? 1, 1, PHP_INT_MAX, 1);
$requestedFinePage = clamp_int($_POST['fine_page'] ?? $_GET['fine_page'] ?? 1, 1, PHP_INT_MAX, 1);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    try {
        if ($action === 'return_loan' || $action === 'mark_lost') {
            $loanId = filter_var($_POST['loan_id'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($loanId) || $loanId < 1) {
                throw new InvalidArgumentException(tr('error_generic'));
            }

            if ($action === 'return_loan') {
                $fine = return_book_loan($pdo, $loanId, (int) current_user()['id']);
                $message = tr('loan_returned');
                if ($fine > 0) {
                    $message .= ' ' . tr('late_fine') . ': ' . format_iqd($fine) . '.';
                }
            } else {
                mark_loan_copy_lost($pdo, $loanId, (int) current_user()['id']);
                $message = tr('loan_marked_lost');
            }
            set_flash('success', $message);
        } elseif ($action === 'settle_fine') {
            $fineId = filter_var($_POST['fine_id'] ?? null, FILTER_VALIDATE_INT);
            $outcome = is_string($_POST['outcome'] ?? null) ? $_POST['outcome'] : '';
            if (!is_int($fineId) || $fineId < 1) {
                throw new InvalidArgumentException(tr('error_generic'));
            }
            settle_staff_fine($pdo, $fineId, $outcome, (int) current_user()['id']);
            set_flash('success', $outcome === 'paid' ? tr('fine_marked_paid') : tr('fine_waived'));
        } else {
            throw new InvalidArgumentException(tr('error_generic'));
        }
    } catch (DomainException|InvalidArgumentException $exception) {
        set_flash('error', tr('error_generic'));
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        set_flash('error', tr('error_generic'));
    }
    redirect_to(app_url('admin/loans.php?' . http_build_query(['loan_page' => $requestedLoanPage, 'fine_page' => $requestedFinePage])));
}

$loanPageSize = 50;
$activeLoanCount = (int) $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'active'")->fetchColumn();
$loanPageCount = max(1, (int) ceil($activeLoanCount / $loanPageSize));
$loanPage = clamp_int($_GET['loan_page'] ?? 1, 1, $loanPageCount, 1);
$loanOffset = ($loanPage - 1) * $loanPageSize;
$loanQuery = $pdo->prepare(
    "SELECT l.id, l.borrowed_at, l.due_at,
        GREATEST(DATEDIFF(CURRENT_DATE, l.due_at), 0) AS overdue_days,
        b.title, u.first_name, u.last_name, c.inventory_code
     FROM loans l
     JOIN book_copies c ON c.id = l.copy_id
     JOIN books b ON b.id = c.book_id
     JOIN users u ON u.id = l.user_id
     WHERE l.status = 'active'
     ORDER BY l.due_at, l.id LIMIT :limit OFFSET :offset"
);
$loanQuery->bindValue(':limit', $loanPageSize, PDO::PARAM_INT);
$loanQuery->bindValue(':offset', $loanOffset, PDO::PARAM_INT);
$loanQuery->execute();
$loans = $loanQuery->fetchAll(PDO::FETCH_ASSOC);
$finePageSize = 50;
$unpaidFineCount = (int) $pdo->query("SELECT COUNT(*) FROM fines WHERE status = 'unpaid'")->fetchColumn();
$finePageCount = max(1, (int) ceil($unpaidFineCount / $finePageSize));
$finePage = clamp_int($_GET['fine_page'] ?? 1, 1, $finePageCount, 1);
$fineOffset = ($finePage - 1) * $finePageSize;
$fineQuery = $pdo->prepare(
    "SELECT f.id, f.amount_iqd, f.assessed_at, b.title, u.first_name, u.last_name
     FROM fines f
     JOIN loans l ON l.id = f.loan_id
     JOIN book_copies c ON c.id = l.copy_id
     JOIN books b ON b.id = c.book_id
     JOIN users u ON u.id = l.user_id
     WHERE f.status = 'unpaid'
     ORDER BY f.assessed_at, f.id LIMIT :limit OFFSET :offset"
);
$fineQuery->bindValue(':limit', $finePageSize, PDO::PARAM_INT);
$fineQuery->bindValue(':offset', $fineOffset, PDO::PARAM_INT);
$fineQuery->execute();
$unpaidFines = $fineQuery->fetchAll(PDO::FETCH_ASSOC);$dailyFineRate = setting_int($pdo, 'late_fine_per_day_iqd', 1000, 0, 100000000);
$success = take_flash('success');
$error = take_flash('error');
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('manage_loans'), 'staff');
?>
<section class="page-wrap admin-shell">
    <p class="eyebrow"><?= escape_html(tr('staff')) ?></p>
    <div class="section-heading">
        <div>
            <h1><?= escape_html(tr('manage_loans')) ?></h1>
            <p class="form-intro"><?= escape_html(tr('fine_policy')) ?> <?= escape_html(format_iqd($dailyFineRate)) ?></p>
        </div>
        <a class="button button-outline" href="<?= escape_html(app_url('admin/reservations.php')) ?>"><?= escape_html(tr('manage_reservations')) ?></a>
    </div>
    <?php if ($success !== null): ?><p class="flash flash-success" role="status"><?= escape_html($success) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="form-error" role="alert"><?= escape_html($error) ?></p><?php endif; ?>

    <section class="account-card">
        <h2><?= escape_html(tr('manage_loans')) ?></h2>
        <?php if ($loans === []): ?>
            <p><?= escape_html(tr('no_active_loans')) ?></p>
        <?php else: ?>
            <div class="table-wrap"><table class="data-table">
                <thead><tr>
                    <th scope="col"><?= escape_html(tr('book')) ?></th>
                    <th scope="col"><?= escape_html(tr('member')) ?></th>
                    <th scope="col"><?= escape_html(tr('copy_inventory')) ?></th>
                    <th scope="col"><?= escape_html(tr('borrowed_at')) ?></th>
                    <th scope="col"><?= escape_html(tr('due_date')) ?></th>
                    <th scope="col"><?= escape_html(tr('late_fine')) ?></th>
                    <th scope="col"><?= escape_html(tr('actions')) ?></th>
                </tr></thead>
                <tbody><?php foreach ($loans as $loan):
                    $estimatedFine = calculate_late_fine((int) $loan['overdue_days'], $dailyFineRate);
                ?>
                    <tr>
                        <td><?= escape_html($loan['title']) ?></td>
                        <td><?= escape_html($loan['first_name'] . ' ' . $loan['last_name']) ?></td>
                        <td><code><?= escape_html($loan['inventory_code']) ?></code></td>
                        <td><?= escape_html($loan['borrowed_at']) ?></td>
                        <td><?= escape_html($loan['due_at']) ?></td>
                        <td>
                            <?= $estimatedFine > 0 ? escape_html(format_iqd($estimatedFine)) : '—' ?>
                            <?php if ($estimatedFine > 0): ?><small><?= escape_html(tr('estimated')) ?></small><?php endif; ?>
                        </td>
                        <td class="admin-actions">
                            <form method="post" action="<?= escape_html(app_url('admin/loans.php')) ?>">
                                <?= csrf_field() ?>
<input type="hidden" name="loan_page" value="<?= $loanPage ?>">
<input type="hidden" name="fine_page" value="<?= $finePage ?>">
                                <input type="hidden" name="action" value="return_loan">
                                <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                                <button class="button button-small" type="submit"><?= escape_html(tr('mark_returned')) ?></button>
                            </form>
                            <form method="post" action="<?= escape_html(app_url('admin/loans.php')) ?>">
                                <?= csrf_field() ?>
<input type="hidden" name="loan_page" value="<?= $loanPage ?>">
<input type="hidden" name="fine_page" value="<?= $finePage ?>">
                                <input type="hidden" name="action" value="mark_lost">
                                <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                                <button class="button button-small button-outline" type="submit"><?= escape_html(tr('mark_loan_lost')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
<?php if ($loanPageCount > 1): ?>
<nav class="pagination" aria-label="<?= escape_html(tr('manage_loans')) ?>">
<?php if ($loanPage > 1): ?><a href="<?= escape_html(app_url('admin/loans.php?' . http_build_query(['loan_page' => $loanPage - 1, 'fine_page' => $finePage]))) ?>"><?= escape_html(tr('previous')) ?></a><?php endif; ?>
<span><?= escape_html(tr('page')) ?> <?= $loanPage ?> <?= escape_html(tr('of')) ?> <?= $loanPageCount ?></span>
<?php if ($loanPage < $loanPageCount): ?><a href="<?= escape_html(app_url('admin/loans.php?' . http_build_query(['loan_page' => $loanPage + 1, 'fine_page' => $finePage]))) ?>"><?= escape_html(tr('next')) ?></a><?php endif; ?>
</nav>
<?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="account-card">
        <h2><?= escape_html(tr('outstanding_fines')) ?></h2>
        <?php if ($unpaidFines === []): ?>
            <p><?= escape_html(tr('no_unpaid_fines')) ?></p>
        <?php else: ?>
            <div class="table-wrap"><table class="data-table">
                <thead><tr>
                    <th scope="col"><?= escape_html(tr('book')) ?></th>
                    <th scope="col"><?= escape_html(tr('member')) ?></th>
                    <th scope="col"><?= escape_html(tr('late_fine')) ?></th>
<th scope="col"><?= escape_html(tr('fine_assessed_at')) ?></th>
                    <th scope="col"><?= escape_html(tr('actions')) ?></th>
                </tr></thead>
                <tbody><?php foreach ($unpaidFines as $fine): ?>
                    <tr>
                        <td><?= escape_html($fine['title']) ?></td>
                        <td><?= escape_html($fine['first_name'] . ' ' . $fine['last_name']) ?></td>
                        <td><?= escape_html(format_iqd((int) $fine['amount_iqd'])) ?></td>
                        <td><?= escape_html($fine['assessed_at']) ?></td>
                        <td class="admin-actions">
                            <?php foreach (['paid' => 'mark_fine_paid', 'waived' => 'waive_fine'] as $outcome => $label): ?>
                                <form method="post" action="<?= escape_html(app_url('admin/loans.php')) ?>">
                                    <?= csrf_field() ?>
<input type="hidden" name="loan_page" value="<?= $loanPage ?>">
<input type="hidden" name="fine_page" value="<?= $finePage ?>">
                                    <input type="hidden" name="action" value="settle_fine">
                                    <input type="hidden" name="fine_id" value="<?= (int) $fine['id'] ?>">
                                    <input type="hidden" name="outcome" value="<?= escape_html($outcome) ?>">
                                    <button class="button button-small" type="submit"><?= escape_html(tr($label)) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
<?php if ($finePageCount > 1): ?>
<nav class="pagination" aria-label="<?= escape_html(tr('outstanding_fines')) ?>">
    <?php if ($finePage > 1): ?><a href="<?= escape_html(app_url('admin/loans.php?' . http_build_query(['loan_page' => $loanPage, 'fine_page' => $finePage - 1]))) ?>"><?= escape_html(tr('previous')) ?></a><?php endif; ?>
    <span><?= escape_html(tr('page')) ?> <?= $finePage ?> <?= escape_html(tr('of')) ?> <?= $finePageCount ?></span>
    <?php if ($finePage < $finePageCount): ?><a href="<?= escape_html(app_url('admin/loans.php?' . http_build_query(['loan_page' => $loanPage, 'fine_page' => $finePage + 1]))) ?>"><?= escape_html(tr('next')) ?></a><?php endif; ?>
</nav>
<?php endif; ?>
        <?php endif; ?>
    </section>
</section>
<?php render_footer(); ?>
