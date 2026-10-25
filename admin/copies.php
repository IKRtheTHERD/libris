<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_staff();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/copy_inventory.php';

$pdo = db();
$error = null;
$success = take_flash('success');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();

    $copyId = filter_var($_POST['copy_id'] ?? null, FILTER_VALIDATE_INT);
    $expectedStatus = $_POST['expected_status'] ?? null;
    $targetStatus = $_POST['status'] ?? null;
    try {
        if (!is_int($copyId) || $copyId < 1 || !is_string($expectedStatus) || !is_string($targetStatus)) {
            throw new InvalidArgumentException('Invalid inventory update.');
        }
        change_copy_inventory_status($pdo, $copyId, $expectedStatus, $targetStatus, (int) current_user()['id']);
        set_flash('success', tr('changes_saved'));
        redirect_to(app_url('admin/copies.php'));
    } catch (InvalidArgumentException|DomainException $exception) {
        $error = tr('error_generic');
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        $error = tr('error_generic');
    }
}

$query = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$purpose = $_GET['purpose'] ?? '';
$status = $_GET['status'] ?? '';
$purposes = ['sale', 'loan'];
$statuses = ['available', 'reserved', 'sold', 'loaned', 'lost', 'retired'];
if (!is_string($purpose) || !in_array($purpose, $purposes, true)) {
    $purpose = '';
}
if (!is_string($status) || !in_array($status, $statuses, true)) {
    $status = '';
}

$conditions = [];
$parameters = [];
if ($query !== '') {
    $conditions[] = '(b.title LIKE :title_query OR c.inventory_code LIKE :code_query)';
    $parameters['title_query'] = '%' . mb_substr($query, 0, 100) . '%';
    $parameters['code_query'] = '%' . mb_substr($query, 0, 100) . '%';
}
if ($purpose !== '') {
    $conditions[] = 'c.purpose = :purpose';
    $parameters['purpose'] = $purpose;
}
if ($status !== '') {
    $conditions[] = 'c.status = :status';
    $parameters['status'] = $status;
}
$where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
$statement = $pdo->prepare(
    "SELECT c.id, c.book_id, c.inventory_code, c.purpose, c.status, c.created_at, b.title
     FROM book_copies c JOIN books b ON b.id = c.book_id
     {$where}
     ORDER BY b.title, c.purpose, c.inventory_code
     LIMIT :limit OFFSET :offset"
);
$pageSize = 50;
$countStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM book_copies c JOIN books b ON b.id = c.book_id {$where}"
);
$countStatement->execute($parameters);
$totalCopies = (int) $countStatement->fetchColumn();
$pageCount = max(1, (int) ceil($totalCopies / $pageSize));
$page = clamp_int($_GET['page'] ?? 1, 1, $pageCount, 1);
$offset = ($page - 1) * $pageSize;
foreach ($parameters as $name => $value) {
    $statement->bindValue(':' . $name, $value, PDO::PARAM_STR);
}
$statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
$statement->execute();
$copies = $statement->fetchAll(PDO::FETCH_ASSOC);
$paginationParams = array_filter(
    ['q' => $query, 'purpose' => $purpose, 'status' => $status],
    static fn (string $value): bool => $value !== ''
);

require_once __DIR__ . '/../includes/layout.php';
render_header(tr('copy_inventory'), 'staff');
?>
<section class="page-wrap admin-shell">
    <p class="eyebrow"><a href="<?= escape_html(app_url('admin/books.php')) ?>"><?= escape_html(tr('manage_books')) ?></a></p>
    <div class="section-heading">
        <div>
            <h1><?= escape_html(tr('copy_inventory')) ?></h1>
            <p class="form-intro"><?= escape_html(tr('purpose')) ?> · <?= escape_html(tr('status')) ?></p>
        </div>
    </div>
    <?php if ($success !== null): ?><p class="flash flash-success" role="status"><?= escape_html($success) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="form-error" role="alert"><?= escape_html($error) ?></p><?php endif; ?>

    <form class="catalog-search" method="get" action="<?= escape_html(app_url('admin/copies.php')) ?>">
        <label for="q"><?= escape_html(tr('search')) ?></label>
        <input id="q" name="q" type="search" maxlength="100" value="<?= escape_html($query) ?>">
        <label for="purpose-filter"><?= escape_html(tr('purpose')) ?></label>
        <select id="purpose-filter" name="purpose">
            <option value=""><?= escape_html(tr('purpose')) ?></option>
            <?php foreach ($purposes as $purposeOption): ?>
                <option value="<?= escape_html($purposeOption) ?>" <?= $purpose === $purposeOption ? 'selected' : '' ?>><?= escape_html(tr('purpose_' . $purposeOption)) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="status-filter"><?= escape_html(tr('status')) ?></label>
        <select id="status-filter" name="status">
            <option value=""><?= escape_html(tr('status')) ?></option>
            <?php foreach ($statuses as $statusOption): ?>
                <?php $statusLabel = tr('copy_' . $statusOption); ?>
                <?php if ($statusLabel === 'copy_' . $statusOption) { $statusLabel = ucfirst($statusOption); } ?>
                <option value="<?= escape_html($statusOption) ?>" <?= $status === $statusOption ? 'selected' : '' ?>><?= escape_html($statusLabel) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="button button-small" type="submit"><?= escape_html(tr('search_button')) ?></button>
    </form>

    <?php if ($copies === []): ?>
        <p class="empty-state"><?= escape_html(tr('no_copies')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th scope="col"><?= escape_html(tr('book')) ?></th>
                    <th scope="col"><?= escape_html(tr('purpose')) ?></th>
                    <th scope="col"><?= escape_html(tr('copy_inventory')) ?></th>
                    <th scope="col"><?= escape_html(tr('status')) ?></th>
                    <th scope="col"><?= escape_html(tr('actions')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($copies as $copy): ?>
                    <?php
                    $copyStatus = (string) $copy['status'];
                    $statusLabel = tr('copy_' . $copyStatus);
                    if ($statusLabel === 'copy_' . $copyStatus) {
                        $statusLabel = ucfirst($copyStatus);
                    }
                    ?>
                    <tr>
                        <td><?= escape_html($copy['title']) ?></td>
                        <td><?= escape_html(tr('purpose_' . $copy['purpose'])) ?></td>
                        <td><code><?= escape_html($copy['inventory_code']) ?></code></td>
                        <td><span class="status-pill"><?= escape_html($statusLabel) ?></span></td>
                        <td>
                            <?php if (in_array($copyStatus, ['available', 'lost'], true)): ?>
                                <?php $nextStatus = $copyStatus === 'available' ? 'lost' : 'available'; ?>
                                <form class="admin-actions" method="post" action="<?= escape_html(app_url('admin/copies.php')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="copy_id" value="<?= (int) $copy['id'] ?>">
                                    <input type="hidden" name="expected_status" value="<?= escape_html($copyStatus) ?>">
                                    <label class="sr-only" for="copy-status-<?= (int) $copy['id'] ?>"><?= escape_html(tr('status')) ?> — <?= escape_html($copy['title']) ?> (<?= escape_html($copy['inventory_code']) ?>)</label>
                                    <select id="copy-status-<?= (int) $copy['id'] ?>" name="status">
                                        <?php foreach (['available', 'lost'] as $editableStatus): ?>
                                            <?php $editableLabel = tr('copy_' . $editableStatus); ?>
                                            <option value="<?= escape_html($editableStatus) ?>" <?= $nextStatus === $editableStatus ? 'selected' : '' ?>><?= escape_html($editableLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="button button-small" type="submit"><?= escape_html(tr('save_status')) ?></button>
                                </form>
                            <?php else: ?>
                                <span aria-hidden="true">—</span><span class="sr-only"><?= escape_html(tr('actions')) ?>: <?= escape_html($statusLabel) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($pageCount > 1): ?>
        <nav class="pagination" aria-label="<?= escape_html(tr('pagination')) ?>">
            <?php if ($page > 1): ?>
                <a href="<?= escape_html(app_url('admin/copies.php?' . http_build_query($paginationParams + ['page' => $page - 1]))) ?>"><?= escape_html(tr('previous')) ?></a>
            <?php endif; ?>
            <span><?= escape_html(tr('page')) ?> <?= $page ?> <?= escape_html(tr('of')) ?> <?= $pageCount ?></span>
            <?php if ($page < $pageCount): ?>
                <a href="<?= escape_html(app_url('admin/copies.php?' . http_build_query($paginationParams + ['page' => $page + 1]))) ?>"><?= escape_html(tr('next')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
