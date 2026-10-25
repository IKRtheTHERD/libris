<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_staff();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/catalog.php';
$pdo = db();
$bookId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$bookId = $bookId === false ? 0 : (int) $bookId;
$book = [
    'title' => '', 'description' => '', 'isbn13' => '', 'open_library_id' => '', 'cover_url' => '',
    'published_year' => '', 'publisher' => '', 'page_count' => '', 'sale_price_iqd' => '',
];
$authorName = '';
$selectedCategory = '';
$error = null;
$saved = false;

if ($bookId > 0) {
    $find = $pdo->prepare('SELECT * FROM books WHERE id = :id');
    $find->execute(['id' => $bookId]);
    $existing = $find->fetch();
    if (!$existing) {
        http_response_code(404);
        exit(tr('not_found'));
    }
    $book = array_merge($book, $existing);
    $authorQuery = $pdo->prepare('SELECT a.name FROM authors a JOIN book_authors ba ON ba.author_id = a.id WHERE ba.book_id = :id ORDER BY ba.author_order LIMIT 1');
    $authorQuery->execute(['id' => $bookId]);
    $authorName = (string) ($authorQuery->fetchColumn() ?: '');
    $categoryQuery = $pdo->prepare('SELECT c.slug FROM categories c JOIN book_categories bc ON bc.category_id = c.id WHERE bc.book_id = :id ORDER BY c.id LIMIT 1');
    $categoryQuery->execute(['id' => $bookId]);
    $selectedCategory = (string) ($categoryQuery->fetchColumn() ?: '');
}

$categories = catalog_categories($pdo);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();
    $title = trim(is_string($_POST['title'] ?? null) ? $_POST['title'] : '');
    $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
    $isbn13 = preg_replace('/\D/', '', is_string($_POST['isbn13'] ?? null) ? $_POST['isbn13'] : '');
    $isbn13 = $isbn13 === '' ? null : $isbn13;
    $openLibraryId = strtoupper(trim(is_string($_POST['open_library_id'] ?? null) ? $_POST['open_library_id'] : ''));
    $openLibraryId = $openLibraryId === '' ? null : $openLibraryId;
    $coverUrl = trim(is_string($_POST['cover_url'] ?? null) ? $_POST['cover_url'] : '');
    $coverUrl = $coverUrl === '' ? null : $coverUrl;
    $publisher = trim(is_string($_POST['publisher'] ?? null) ? $_POST['publisher'] : '');
    $year = $_POST['published_year'] ?? '';
    $pageCount = $_POST['page_count'] ?? '';
    $price = $_POST['sale_price_iqd'] ?? '';
    $categorySlug = is_string($_POST['category_slug'] ?? null) ? $_POST['category_slug'] : '';
    $authorName = trim(is_string($_POST['author_name'] ?? null) ? $_POST['author_name'] : '');
    $saleCopies = clamp_int($_POST['add_sale_copies'] ?? 0, 0, 20, 0);
    $loanCopies = clamp_int($_POST['add_loan_copies'] ?? 0, 0, 20, 0);

    try {
        if ($title === '' || mb_strlen($title) > 300 || mb_strlen($description) > 20000 || mb_strlen($publisher) > 200 || mb_strlen($authorName) > 200) {
            throw new InvalidArgumentException(tr('check_title_fields'));
        }
        if ($isbn13 !== null && (strlen($isbn13) !== 13 || preg_match('/^\d{13}$/', $isbn13) !== 1)) {
            throw new InvalidArgumentException(tr('invalid_isbn'));
        }
        if ($openLibraryId !== null && preg_match('/^OL\d+[MW]$/', $openLibraryId) !== 1) {
            throw new InvalidArgumentException(tr('invalid_open_library_id'));
        }
        if ($coverUrl !== null && (filter_var($coverUrl, FILTER_VALIDATE_URL) === false || parse_url($coverUrl, PHP_URL_SCHEME) !== 'https' || parse_url($coverUrl, PHP_URL_HOST) !== 'covers.openlibrary.org')) {
            throw new InvalidArgumentException(tr('cover_domain_only'));
        }
        if ($year !== '' && filter_var($year, FILTER_VALIDATE_INT) === false || $year !== '' && ((int) $year < 1 || (int) $year > 2100)) {
            throw new InvalidArgumentException(tr('check_title_fields'));
        }
        if ($pageCount !== '' && filter_var($pageCount, FILTER_VALIDATE_INT) === false || $pageCount !== '' && ((int) $pageCount < 1 || (int) $pageCount > 65535)) {
            throw new InvalidArgumentException(tr('check_title_fields'));
        }
        if ($price !== '' && (filter_var($price, FILTER_VALIDATE_INT) === false || (int) $price < 0 || (int) $price > 2147483647)) {
            throw new InvalidArgumentException(tr('check_title_fields'));
        }
        $categoryIsValid = false;
        foreach ($categories as $category) {
            if ((string) $category['slug'] === $categorySlug) {
                $categoryIsValid = true;
                break;
            }
        }
        if (!$categoryIsValid) {
            throw new InvalidArgumentException(tr('choose_category'));
        }

        $pdo->beginTransaction();
        if ($bookId === 0) {
            if ((int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn() >= 200) {
                throw new DomainException(tr('title_cap_reached'));
            }
            $insert = $pdo->prepare(
                'INSERT INTO books (title, description, language_code, isbn13, open_library_id, cover_url, published_year, publisher, page_count, sale_price_iqd)
                 VALUES (:title, :description, :language, :isbn13, :open_library_id, :cover_url, :year, :publisher, :page_count, :price)'
            );
            $insert->execute([
                'title' => $title, 'description' => $description !== '' ? $description : null, 'language' => current_language(),
                'isbn13' => $isbn13, 'open_library_id' => $openLibraryId, 'cover_url' => $coverUrl,
                'year' => $year !== '' ? (int) $year : null, 'publisher' => $publisher !== '' ? $publisher : null,
                'page_count' => $pageCount !== '' ? (int) $pageCount : null, 'price' => $price !== '' ? (int) $price : null,
            ]);
            $bookId = (int) $pdo->lastInsertId();
            $activity = 'catalog.create';
        } else {
            $update = $pdo->prepare(
                'UPDATE books SET title = :title, description = :description, isbn13 = :isbn13, open_library_id = :open_library_id,
                    cover_url = :cover_url, published_year = :year, publisher = :publisher, page_count = :page_count, sale_price_iqd = :price
                 WHERE id = :id'
            );
            $update->execute([
                'title' => $title, 'description' => $description !== '' ? $description : null, 'isbn13' => $isbn13,
                'open_library_id' => $openLibraryId, 'cover_url' => $coverUrl,
                'year' => $year !== '' ? (int) $year : null, 'publisher' => $publisher !== '' ? $publisher : null,
                'page_count' => $pageCount !== '' ? (int) $pageCount : null, 'price' => $price !== '' ? (int) $price : null, 'id' => $bookId,
            ]);
            $activity = 'catalog.update';
        }

        $findCategory = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug');
        $findCategory->execute(['slug' => $categorySlug]);
        $categoryId = (int) $findCategory->fetchColumn();
        $pdo->prepare('DELETE FROM book_categories WHERE book_id = :id')->execute(['id' => $bookId]);
        $linkCategory = $pdo->prepare('INSERT INTO book_categories (book_id, category_id) VALUES (:book_id, :category_id)');
        $linkCategory->execute(['book_id' => $bookId, 'category_id' => $categoryId]);

        $pdo->prepare('DELETE FROM book_authors WHERE book_id = :id')->execute(['id' => $bookId]);
        if ($authorName !== '') {
            $authorKey = 'LOCAL-' . substr(hash('sha256', mb_strtolower($authorName)), 0, 48);
            $upsertAuthor = $pdo->prepare('INSERT INTO authors (name, open_library_id) VALUES (:name, :key) ON DUPLICATE KEY UPDATE name = VALUES(name)');
            $upsertAuthor->execute(['name' => $authorName, 'key' => $authorKey]);
            $authorId = (int) $pdo->lastInsertId();
            if ($authorId === 0) {
                $lookupAuthor = $pdo->prepare('SELECT id FROM authors WHERE open_library_id = :key');
                $lookupAuthor->execute(['key' => $authorKey]);
                $authorId = (int) $lookupAuthor->fetchColumn();
            }
            $linkAuthor = $pdo->prepare('INSERT INTO book_authors (book_id, author_id, author_order) VALUES (:book_id, :author_id, 1)');
            $linkAuthor->execute(['book_id' => $bookId, 'author_id' => $authorId]);
        }

        $addCopy = $pdo->prepare('INSERT INTO book_copies (book_id, inventory_code, purpose, status) VALUES (:book_id, :code, :purpose, \'available\')');
        foreach ([['sale', $saleCopies], ['loan', $loanCopies]] as [$purpose, $copyCount]) {
            for ($index = 0; $index < $copyCount; $index++) {
                $code = 'LIB-' . $bookId . '-' . strtoupper(substr($purpose, 0, 1)) . '-' . bin2hex(random_bytes(4));
                $addCopy->execute(['book_id' => $bookId, 'code' => $code, 'purpose' => $purpose]);
            }
        }
        record_activity($pdo, (int) current_user()['id'], $activity, 'book', $bookId, ['sale_copies_added' => $saleCopies, 'loan_copies_added' => $loanCopies]);
        $pdo->commit();
        $saved = true;
        $find = $pdo->prepare('SELECT * FROM books WHERE id = :id');
        $find->execute(['id' => $bookId]);
        $book = array_merge($book, $find->fetch() ?: []);
    } catch (InvalidArgumentException|DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        $error = tr('save_failed');
    }
}

$availableCopies = ['sale' => 0, 'loan' => 0];
if ($bookId > 0) {
    $stockQuery = $pdo->prepare("SELECT purpose, COUNT(*) AS available_count FROM book_copies WHERE book_id = :id AND status = 'available' GROUP BY purpose");
    $stockQuery->execute(['id' => $bookId]);
    foreach ($stockQuery->fetchAll() as $row) {
        $availableCopies[(string) $row['purpose']] = (int) $row['available_count'];
    }
}
require_once __DIR__ . '/../includes/layout.php';
render_header($bookId > 0 ? tr('edit_title') : tr('add_title'), 'staff');
?>
<section class="page-wrap admin-shell">
    <p class="eyebrow"><a href="<?= escape_html(app_url('admin/books.php')) ?>"><?= escape_html(tr('manage_books')) ?></a></p>
    <h1><?= escape_html($bookId > 0 ? tr('edit_title') : tr('add_title')) ?></h1>
    <?php if ($saved): ?><p class="flash flash-success" role="status"><?= escape_html(tr('changes_saved')) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="form-error" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
    <form class="admin-form" method="post" action="<?= escape_html(app_url('admin/book-edit.php' . ($bookId > 0 ? '?id=' . $bookId : ''))) ?>">
        <?= csrf_field() ?>
        <div class="form-field span-full"><label for="title"><?= escape_html(tr('book_title')) ?></label><input id="title" name="title" maxlength="300" required value="<?= escape_html($_POST['title'] ?? $book['title']) ?>"></div>
        <div class="form-field span-full"><label for="description"><?= escape_html(tr('description')) ?></label><textarea id="description" name="description" maxlength="20000"><?= escape_html($_POST['description'] ?? $book['description']) ?></textarea></div>
        <div class="form-field"><label for="author-name"><?= escape_html(tr('author')) ?></label><input id="author-name" name="author_name" maxlength="200" value="<?= escape_html($_POST['author_name'] ?? $authorName) ?>"></div>
        <div class="form-field"><label for="category"><?= escape_html(tr('category')) ?></label><select id="category" name="category_slug" required><option value=""><?= escape_html(tr('choose_category')) ?></option><?php foreach ($categories as $category): ?><option value="<?= escape_html($category['slug']) ?>"<?= (($_POST['category_slug'] ?? $selectedCategory) === $category['slug']) ? ' selected' : '' ?>><?= escape_html($category['name_' . current_language()]) ?></option><?php endforeach; ?></select></div>
        <div class="form-field"><label for="isbn13">ISBN-13</label><input id="isbn13" name="isbn13" inputmode="numeric" maxlength="17" value="<?= escape_html($_POST['isbn13'] ?? $book['isbn13']) ?>"></div>
        <div class="form-field"><label for="open-library-id">Open Library ID</label><input id="open-library-id" name="open_library_id" maxlength="80" value="<?= escape_html($_POST['open_library_id'] ?? $book['open_library_id']) ?>"></div>
        <div class="form-field span-full"><label for="cover-url"><?= escape_html(tr('cover_url')) ?></label><input id="cover-url" type="url" name="cover_url" maxlength="500" value="<?= escape_html($_POST['cover_url'] ?? $book['cover_url']) ?>"><small class="form-help"><?= escape_html(tr('cover_domain_only')) ?></small></div>
        <div class="form-field"><label for="year"><?= escape_html(tr('published_year')) ?></label><input id="year" name="published_year" type="number" min="1" max="2100" value="<?= escape_html($_POST['published_year'] ?? $book['published_year']) ?>"></div>
        <div class="form-field"><label for="publisher"><?= escape_html(tr('publisher')) ?></label><input id="publisher" name="publisher" maxlength="200" value="<?= escape_html($_POST['publisher'] ?? $book['publisher']) ?>"></div>
        <div class="form-field"><label for="page-count"><?= escape_html(tr('pages')) ?></label><input id="page-count" name="page_count" type="number" min="1" max="65535" value="<?= escape_html($_POST['page_count'] ?? $book['page_count']) ?>"></div>
        <div class="form-field"><label for="price"><?= escape_html(tr('price')) ?> (IQD)</label><input id="price" name="sale_price_iqd" type="number" min="0" max="2147483647" value="<?= escape_html($_POST['sale_price_iqd'] ?? $book['sale_price_iqd']) ?>"></div>
        <div class="form-field"><label for="sale-copies"><?= escape_html(tr('add_sale_copies')) ?> (<?= $availableCopies['sale'] ?> <?= escape_html(tr('available')) ?>)</label><input id="sale-copies" name="add_sale_copies" type="number" min="0" max="20" value="0"></div>
        <div class="form-field"><label for="loan-copies"><?= escape_html(tr('add_loan_copies')) ?> (<?= $availableCopies['loan'] ?> <?= escape_html(tr('available')) ?>)</label><input id="loan-copies" name="add_loan_copies" type="number" min="0" max="20" value="0"></div>
        <button class="button button-ink span-full" type="submit"><?= escape_html(tr('save_changes')) ?></button>
    </form>
</section>
<?php render_footer(); ?>
