<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/views.php';
require_once __DIR__ . '/../includes/layout.php';

$queryValue = static fn (string $key): string => isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
$authorId = filter_var($queryValue('author_id'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 2147483647],
]);
$filters = [
    'q' => mb_substr($queryValue('q'), 0, 100),
    'category' => preg_match('/^[a-z0-9-]{1,80}$/', $queryValue('category')) ? $queryValue('category') : '',
    'author_id' => $authorId === false ? '' : $authorId,
    'min_price' => $queryValue('min_price'),
    'max_price' => $queryValue('max_price'),
    'availability' => in_array($queryValue('availability'), ['sale', 'loan'], true) ? $queryValue('availability') : '',
    'sort' => in_array($queryValue('sort'), ['recent', 'title', 'price-low', 'price-high', 'rating'], true) ? $queryValue('sort') : 'recent',
];
$language = current_language();
$requestedPage = clamp_int($queryValue('page'), 1, 10000, 1);
$result = ['books' => [], 'total' => 0, 'page' => $requestedPage, 'pages' => 1];
$categories = [];
$authors = [];
$databaseUnavailable = false;
try {
    $pdo = db();
    $categories = catalog_categories($pdo);
    $authors = catalog_authors($pdo);
    $result = search_catalog($pdo, $filters, $requestedPage, 12);
    if ($result['total'] > 0 && $requestedPage > $result['pages']) {
        $result = search_catalog($pdo, $filters, $result['pages'], 12);
    }
} catch (PDOException) {
    $databaseUnavailable = true;
    http_response_code(503);
}

$pageTitle = $filters['q'] !== '' ? tr('results_for') . ' “' . $filters['q'] . '”' : tr('browse');
render_header($pageTitle, 'discover');
?>
<section class="page-wrap page-heading">
    <nav class="breadcrumbs" aria-label="<?= escape_html(tr('breadcrumb')) ?>">
        <a href="<?= escape_html(app_url()) ?>">Libris</a><span aria-hidden="true">/</span><span><?= escape_html(tr('discover')) ?></span>
    </nav>
    <p class="section-kicker"><?= escape_html(tr('books')) ?></p>
    <h1 class="page-title"><?= escape_html(tr('browse')) ?></h1>
    <p class="page-lede"><?= escape_html(tr('curated_copy')) ?></p>
</section>

<div class="page-wrap catalog-layout">
    <aside class="filter-panel" aria-label="<?= escape_html(tr('apply_filters')) ?>">
        <h2><?= escape_html(tr('search')) ?> &amp; <?= escape_html(tr('categories')) ?></h2>
        <form action="<?= escape_html(app_url('books/index.php')) ?>" method="get">
            <div class="filter-group">
                <label for="catalog-q"><?= escape_html(tr('search')) ?></label>
                <input id="catalog-q" type="search" name="q" value="<?= escape_html($filters['q']) ?>" maxlength="100" placeholder="<?= escape_html(tr('search_placeholder')) ?>">
            </div>
            <div class="filter-group">
                <label for="catalog-category"><?= escape_html(tr('categories')) ?></label>
                <select id="catalog-category" name="category">
                    <option value=""><?= escape_html(tr('all_categories')) ?></option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= escape_html($category['slug']) ?>"<?= $filters['category'] === $category['slug'] ? ' selected' : '' ?>>
                            <?= escape_html($language === 'ckb' ? $category['name_ckb'] : $category['name_en']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="catalog-author"><?= escape_html(tr('author')) ?></label>
                <select id="catalog-author" name="author_id">
                    <option value="">—</option>
                    <?php foreach ($authors as $author): ?>
                        <option value="<?= (int) $author['id'] ?>"<?= $filters['author_id'] === (int) $author['id'] ? ' selected' : '' ?>><?= escape_html($author['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label"><?= escape_html(tr('availability')) ?></span>
                <select name="availability" aria-label="<?= escape_html(tr('availability')) ?>">
                    <option value=""><?= escape_html(tr('any_availability')) ?></option>
                    <option value="sale"<?= $filters['availability'] === 'sale' ? ' selected' : '' ?>><?= escape_html(tr('for_sale')) ?></option>
                    <option value="loan"<?= $filters['availability'] === 'loan' ? ' selected' : '' ?>><?= escape_html(tr('to_borrow')) ?></option>
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label"><?= escape_html(tr('price')) ?></span>
                <div class="filter-stack">
                    <input type="number" inputmode="numeric" min="0" max="100000000" name="min_price" aria-label="<?= escape_html(tr('min_price')) ?>" placeholder="<?= escape_html(tr('min_price')) ?>" value="<?= escape_html($filters['min_price']) ?>">
                    <input type="number" inputmode="numeric" min="0" max="100000000" name="max_price" aria-label="<?= escape_html(tr('max_price')) ?>" placeholder="<?= escape_html(tr('max_price')) ?>" value="<?= escape_html($filters['max_price']) ?>">
                </div>
            </div>
            <input type="hidden" name="sort" value="<?= escape_html($filters['sort']) ?>">
            <button class="button button-ink" type="submit"><?= escape_html(tr('apply_filters')) ?></button>
            <a class="button button-text" href="<?= escape_html(app_url('books/index.php')) ?>"><?= escape_html(tr('clear_filters')) ?></a>
        </form>
    </aside>

    <section class="catalog-results" aria-labelledby="results-heading">
        <h2 class="sr-only" id="results-heading"><?= escape_html(tr('books')) ?></h2>
        <div class="results-topline">
            <span><strong><?= (int) $result['total'] ?></strong> <?= escape_html(tr('books')) ?></span>
            <form action="<?= escape_html(app_url('books/index.php')) ?>" method="get">
                <?php foreach ($filters as $key => $value): if ($value !== ''): ?>
                    <input type="hidden" name="<?= escape_html($key) ?>" value="<?= escape_html($value) ?>">
                <?php endif; endforeach; ?>
                <label class="sr-only" for="catalog-sort"><?= escape_html(tr('sort')) ?></label>
                <select id="catalog-sort" name="sort" data-auto-submit>
                    <option value="recent"<?= $filters['sort'] === 'recent' ? ' selected' : '' ?>><?= escape_html(tr('recent')) ?></option>
                    <option value="title"<?= $filters['sort'] === 'title' ? ' selected' : '' ?>><?= escape_html(tr('title_az')) ?></option>
                    <option value="price-low"<?= $filters['sort'] === 'price-low' ? ' selected' : '' ?>><?= escape_html(tr('price_low')) ?></option>
                    <option value="price-high"<?= $filters['sort'] === 'price-high' ? ' selected' : '' ?>><?= escape_html(tr('price_high')) ?></option>
                    <option value="rating"<?= $filters['sort'] === 'rating' ? ' selected' : '' ?>><?= escape_html(tr('top_rated')) ?></option>
                </select>
            </form>
        </div>
        <?php if ($databaseUnavailable): ?>
            <div class="empty-state"><strong><?= escape_html(tr('curated_shelf')) ?></strong><?= escape_html(tr('nothing_here')) ?><br><small><?= escape_html(tr('catalogue_search_setup')) ?></small></div>
        <?php elseif ($result['books'] !== []): ?>
            <div class="book-grid catalog-grid">
                <?php foreach ($result['books'] as $book) render_book_card($book); ?>
            </div>
            <?php if ($result['pages'] > 1): ?>
                    <nav class="pagination" aria-label="<?= escape_html(tr('pagination')) ?>">
                    <?php $queryParams = array_filter($filters, static fn ($value): bool => $value !== ''); ?>
                    <?php if ($result['page'] > 1): ?><a href="<?= escape_html(app_url('books/index.php?' . http_build_query($queryParams + ['page' => $result['page'] - 1]))) ?>" aria-label="<?= escape_html(tr('previous')) ?>">←</a><?php endif; ?>
                    <span class="is-current" aria-current="page"><?= (int) $result['page'] ?> / <?= (int) $result['pages'] ?></span>
                    <?php if ($result['page'] < $result['pages']): ?><a href="<?= escape_html(app_url('books/index.php?' . http_build_query($queryParams + ['page' => $result['page'] + 1]))) ?>" aria-label="<?= escape_html(tr('next')) ?>">→</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state"><strong><?= escape_html(tr('nothing_here')) ?></strong><?= escape_html(tr('no_books')) ?></div>
        <?php endif; ?>
    </section>
</div>
<?php render_footer(); ?>
