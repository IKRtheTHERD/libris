<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/views.php';
require_once __DIR__ . '/includes/layout.php';

$pdo = null;
$featured = [];
$newArrivals = [];
$categories = [];
$bookCount = null;
$language = current_language();
$setupRequired = false;

try {
    $pdo = db();
    $featured = search_catalog($pdo, ['sort' => 'rating'], 1, 4)['books'];
    $newArrivals = search_catalog($pdo, ['sort' => 'recent'], 1, 4)['books'];
    $categories = catalog_categories($pdo);
    $bookCount = (int) $pdo->query('SELECT COUNT(*) FROM books WHERE is_active = 1')->fetchColumn();
} catch (PDOException) {
    $setupRequired = true;
}

render_header(tr('discover'), 'discover');
?>
<section class="home-hero">
    <div class="hero-inner">
        <div class="hero-copy" data-reveal>
            <p class="eyebrow"><?= escape_html(tr('hero_eyebrow')) ?></p>
            <h1 class="hero-title"><?= tr('hero_title') ?></h1>
            <p class="hero-description"><?= escape_html(tr('hero_copy')) ?></p>
            <div class="hero-actions">
                <a class="button button-ink" href="<?= escape_html(app_url('books/index.php')) ?>">
                    <?= escape_html(tr('explore_shelf')) ?><span class="button-arrow" aria-hidden="true">→</span>
                </a>
                <a class="button button-text" href="<?= escape_html(app_url('books/index.php?availability=loan')) ?>"><?= escape_html(tr('read_more')) ?></a>
            </div>
        </div>

        <div class="hero-art" aria-label="<?= escape_html(tr('hero_art_alt')) ?>" role="img" data-reveal>
            <div class="hero-orbit" aria-hidden="true"></div>
            <div class="hero-sun" aria-hidden="true"></div>
            <div class="hero-stamp" aria-hidden="true">
                <span><?= escape_html(tr('hero_established')) ?><strong><?= date('Y') ?></strong></span>
            </div>
            <div class="hero-book" aria-hidden="true">
                <span class="hero-book-kicker"><?= escape_html(tr('hero_book_kicker')) ?></span>
                <span class="hero-book-title"><?= escape_html(tr('hero_book_title_first')) ?><br><?= escape_html(tr('hero_book_title_second')) ?></span>
                <span class="hero-book-flower" aria-hidden="true">✳</span>
                <span class="hero-book-author"><?= escape_html(tr('hero_book_author')) ?></span>
            </div>
            <span class="hero-note"><?= escape_html(tr('hero_city')) ?></span>
        </div>
    </div>
    <div class="hero-bottomline" aria-hidden="true"></div>
</section>

<div class="page-wrap intro-band">
    <p><?= escape_html(tr('curated_copy')) ?></p>
    <?php if ($bookCount !== null): ?>
        <div class="intro-stat">
            <strong><?= number_format($bookCount) ?></strong>
            <span><?= escape_html(tr('books')) ?></span>
            <span aria-hidden="true">·</span>
            <strong><?= number_format(count($categories)) ?></strong>
            <span><?= escape_html(tr('categories')) ?></span>
        </div>
    <?php endif; ?>
</div>

<?php if ($setupRequired): ?>
    <div class="page-wrap setup-notice">
        <div class="empty-state">
            <strong><?= escape_html(tr('nothing_here')) ?></strong>
            <span><?= escape_html(tr('setup_catalogue_help')) ?></span>
        </div>
    </div>
<?php endif; ?>

<?php if ($categories !== []): ?>
    <section class="category-strip" aria-label="<?= escape_html(tr('categories')) ?>">
        <div class="page-wrap category-list">
            <?php foreach (array_slice($categories, 0, 10) as $category): ?>
                <?php
                $categoryName = $language === 'ckb' ? ($category['name_ckb'] ?? '') : ($category['name_en'] ?? '');
                $categoryName = trim((string) $categoryName) !== '' ? $categoryName : ($category['name_en'] ?? '');
                ?>
                <a class="category-chip" href="<?= escape_html(app_url('books/index.php?category=' . rawurlencode((string) $category['slug']))) ?>">
                    <?= escape_html($categoryName) ?><span><?= number_format((int) $category['book_count']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="section page-wrap">
    <div class="section-heading">
        <div class="section-heading-copy">
            <p class="section-kicker"><?= escape_html(tr('featured')) ?></p>
            <h2 class="section-title"><?= escape_html(tr('curated_shelf')) ?></h2>
            <p class="section-description"><?= escape_html(tr('curated_copy')) ?></p>
        </div>
        <a class="text-link" href="<?= escape_html(app_url('books/index.php?sort=rating')) ?>">
            <?= escape_html(tr('see_all')) ?><span aria-hidden="true">→</span>
        </a>
    </div>
    <?php if ($featured !== []): ?>
        <div class="book-grid">
            <?php foreach ($featured as $book) { render_book_card($book, $language); } ?>
        </div>
    <?php elseif (!$setupRequired): ?>
        <div class="empty-state"><strong><?= escape_html(tr('nothing_here')) ?></strong><?= escape_html(tr('no_books')) ?></div>
    <?php endif; ?>
</section>

<?php if ($newArrivals !== []): ?>
    <div class="page-wrap shelf-divider"><span><?= escape_html(tr('shelf_note')) ?></span></div>
    <section class="section page-wrap">
        <div class="section-heading">
            <div class="section-heading-copy">
                <p class="section-kicker"><?= escape_html(tr('new_arrivals')) ?></p>
                <h2 class="section-title"><?= escape_html(tr('new_arrivals')) ?></h2>
            </div>
            <a class="text-link" href="<?= escape_html(app_url('books/index.php?sort=recent')) ?>">
                <?= escape_html(tr('see_all')) ?><span aria-hidden="true">→</span>
            </a>
        </div>
        <div class="book-grid">
            <?php foreach ($newArrivals as $book) { render_book_card($book, $language); } ?>
        </div>
    </section>
<?php endif; ?>

<section class="section page-wrap">
    <div class="library-callout">
        <div>
            <p class="section-kicker"><?= escape_html(tr('library')) ?></p>
            <h2><?= escape_html(tr('read_more')) ?>. <?= escape_html(tr('tagline')) ?></h2>
            <p><?= escape_html(tr('hero_copy')) ?></p>
        </div>
        <a class="button button-outline" href="<?= escape_html(app_url('books/index.php?availability=loan')) ?>">
            <?= escape_html(tr('to_borrow')) ?><span class="button-arrow" aria-hidden="true">→</span>
        </a>
    </div>
</section>
<?php render_footer(); ?>