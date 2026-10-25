<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_staff();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/book_deletion.php';
$pdo = db();
$error = take_flash('error');
$success = take_flash('success');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();
    $bookId = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    if (!is_int($bookId) || $bookId < 1 || !in_array($action, ['archive', 'restore', 'delete'], true)) {
        $error = tr('error_generic');
    } elseif ($action === 'delete') {
        try {
            delete_unused_book($pdo, $bookId, (int) current_user()['id']);
            set_flash('success', tr('title_deleted'));
            header('Location: ' . app_url('admin/books.php'));
            exit;
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $error = tr('error_generic');
        }
    } else {
        $active = $action === 'restore' ? 1 : 0;
        $update = $pdo->prepare('UPDATE books SET is_active = :active WHERE id = :id');
        $update->execute(['active' => $active, 'id' => $bookId]);
        if ($update->rowCount() > 0) {
            record_activity($pdo, (int) current_user()['id'], 'catalog.' . $action, 'book', $bookId);
        }
    }
}
$query = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$parameters = [];
$where = '';
if ($query !== '') {
    $where = 'WHERE b.title LIKE :title_query OR b.open_library_id LIKE :id_query';
    $parameters['title_query'] = '%' . $query . '%';
    $parameters['id_query'] = '%' . $query . '%';
}
$statement = $pdo->prepare(
    'SELECT b.id, b.title, b.is_active, b.sale_price_iqd,
            (SELECT COUNT(*) FROM book_copies c WHERE c.book_id = b.id AND c.purpose = \'sale\' AND c.status = \'available\') AS sale_available,
    (SELECT COUNT(*) FROM book_copies c WHERE c.book_id = b.id AND c.purpose = \'loan\' AND c.status = \'available\') AS loan_available,
    CASE WHEN NOT EXISTS (SELECT 1 FROM book_copies c WHERE c.book_id = b.id AND c.status <> \'available\')
      AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.book_id = b.id)
      AND NOT EXISTS (SELECT 1 FROM loans l JOIN book_copies c ON c.id = l.copy_id WHERE c.book_id = b.id)
      AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.book_id = b.id)
      AND NOT EXISTS (SELECT 1 FROM cart_items ci WHERE ci.book_id = b.id)
      AND NOT EXISTS (SELECT 1 FROM reviews rv WHERE rv.book_id = b.id)
      AND NOT EXISTS (SELECT 1 FROM wishlist w WHERE w.book_id = b.id)
      THEN 1 ELSE 0 END AS delete_eligible
     FROM books b ' . $where . ' ORDER BY b.title LIMIT 200'
);
$statement->execute($parameters);
$books = $statement->fetchAll();
$totalTitles = (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('manage_books'), 'staff');
?>
<section class="page-wrap admin-shell">
    <p class="eyebrow"><?= escape_html(tr('staff')) ?></p>
    <div class="section-heading"><div><h1><?= escape_html(tr('manage_books')) ?></h1><p class="form-intro"><?= escape_html(tr('title_limit')) ?> <?= $totalTitles ?>/200</p></div>
 <a class="button button-small" href="<?= escape_html(app_url('admin/copies.php')) ?>"><?= escape_html(tr('copy_inventory')) ?></a>
 <a class="button button-ink" href="<?= escape_html(app_url('admin/book-edit.php')) ?>"><?= escape_html(tr('add_title')) ?></a></div>
    <?php if ($success !== null): ?><p class="flash flash-success" role="status"><?= escape_html($success) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="form-error" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
    <form class="catalog-search" method="get" action="<?= escape_html(app_url('admin/books.php')) ?>">
        <label for="q"><?= escape_html(tr('search')) ?></label><input id="q" name="q" value="<?= escape_html($query) ?>" maxlength="100"><button class="button button-small" type="submit"><?= escape_html(tr('search_button')) ?></button>
    </form>
    <div class="table-wrap"><table class="data-table"><thead><tr><th><?= escape_html(tr('book')) ?></th><th><?= escape_html(tr('for_sale')) ?></th><th><?= escape_html(tr('to_borrow')) ?></th><th><?= escape_html(tr('price')) ?></th><th><?= escape_html(tr('status')) ?></th><th><?= escape_html(tr('actions')) ?></th></tr></thead>
        <tbody><?php foreach ($books as $book): ?><tr>
            <td><?= escape_html($book['title']) ?></td><td><?= (int) $book['sale_available'] ?></td><td><?= (int) $book['loan_available'] ?></td>
            <td><?= $book['sale_price_iqd'] === null ? '—' : escape_html(format_iqd((int) $book['sale_price_iqd'])) ?></td>
            <td><span class="status-pill is-<?= (bool) $book['is_active'] ? 'active' : 'cancelled' ?>"><?= escape_html(tr((bool) $book['is_active'] ? 'active' : 'archived')) ?></span></td>
            <td class="admin-actions"><a href="<?= escape_html(app_url('admin/book-edit.php?id=' . (int) $book['id'])) ?>"><?= escape_html(tr('edit_title')) ?></a>
                <form method="post" action="<?= escape_html(app_url('admin/books.php')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>"><input type="hidden" name="action" value="<?= (bool) $book['is_active'] ? 'archive' : 'restore' ?>">
                    <button class="button-text" type="submit"><?= escape_html(tr((bool) $book['is_active'] ? 'archive_title' : 'restore_title')) ?></button>
                </form>
                <?php if ((bool) $book['delete_eligible']): ?>
                    <form method="post" action="<?= escape_html(app_url('admin/books.php')) ?>" onsubmit="return confirm(<?= escape_html((string) json_encode(tr('confirm_delete_title'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>)">
                        <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>"><input type="hidden" name="action" value="delete">
                        <button class="button-text" type="submit"><?= escape_html(tr('delete_title')) ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php if ($books === []): ?><p class="empty-state"><?= escape_html(tr('no_titles')) ?></p><?php endif; ?>
</section>
<?php render_footer(); ?>
