<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/views.php';
require_once __DIR__ . '/../includes/layout.php';

$parsedBookId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$bookId = is_int($parsedBookId) && $parsedBookId > 0 ? $parsedBookId : 0;
$language = current_language();
$book = null;
$reviews = [];
$isSaved = false;
$databaseUnavailable = false;
try {
    $pdo = db();
    $book = catalog_book($pdo, $bookId);
    if ($book !== null) {
        $reviews = book_reviews($pdo, $bookId);
        if (is_authenticated()) {
            $saved = $pdo->prepare('SELECT 1 FROM wishlist WHERE user_id = :user_id AND book_id = :book_id');
            $saved->execute(['user_id' => current_user()['id'], 'book_id' => $bookId]);
            $isSaved = (bool) $saved->fetchColumn();
        }
    }
} catch (PDOException) {
    $databaseUnavailable = true;
    http_response_code(503);
}

$openLibraryId = (string) ($book['open_library_id'] ?? '');
$openLibraryPath = catalog_open_library_path($openLibraryId);

if (!$databaseUnavailable && $book === null) {
    http_response_code(404);
}
$pageTitle = $book === null ? tr('book') : (string) $book['title'];
render_header($pageTitle);
?>
<section class="page-wrap page-heading">
    <nav class="breadcrumbs" aria-label="<?= escape_html(tr('breadcrumb')) ?>">
        <a href="<?= escape_html(app_url()) ?>">Libris</a><span aria-hidden="true">/</span>
        <a href="<?= escape_html(app_url('books/index.php')) ?>"><?= escape_html(tr('browse')) ?></a><span aria-hidden="true">/</span>
        <span><?= escape_html($pageTitle) ?></span>
    </nav>
</section>
<?php if ($databaseUnavailable): ?>
    <div class="page-wrap form-page"><div class="empty-state"><strong><?= escape_html(tr('curated_shelf')) ?></strong><?= escape_html(tr('nothing_here')) ?></div></div>
<?php elseif ($book === null): ?>
    <div class="page-wrap form-page"><div class="empty-state"><strong><?= escape_html(tr('not_found')) ?></strong><a class="text-link" href="<?= escape_html(app_url('books/index.php')) ?>"><?= escape_html(tr('back_home')) ?></a></div></div>
<?php else: ?>
    <section class="page-wrap book-detail">
        <div class="detail-cover" data-reveal>
            <img src="<?= escape_html(book_cover_source($book)) ?>" alt="<?= escape_html(tr('book')) ?>: <?= escape_html($book['title']) ?>" width="480" height="680" data-book-cover>
        </div>
        <div class="detail-copy" data-reveal>
            <p class="section-kicker"><?= escape_html(tr('books')) ?></p>
            <h1><?= escape_html($book['title']) ?></h1>
            <?php if (!empty($book['subtitle'])): ?><p class="detail-subtitle"><?= escape_html($book['subtitle']) ?></p><?php endif; ?>
            <?php if (!empty($book['authors'])): ?><p class="detail-author"><?= escape_html(tr('by')) ?> <?= escape_html(implode(', ', $book['authors'])) ?></p><?php endif; ?>
            <?php if ((int) $book['review_count'] > 0): ?>
                <p class="detail-rating"><span aria-hidden="true">★★★★★</span><strong><?= number_format((float) $book['average_rating'], 1) ?></strong><span>(<?= (int) $book['review_count'] ?> <?= escape_html(tr('reviews')) ?>)</span></p>
            <?php endif; ?>
            <?php if (!empty($book['description'])): ?><p class="detail-description"><?= escape_html($book['description']) ?></p><?php endif; ?>

            <div class="detail-facts">
                <?php if (!empty($book['published_year'])): ?><div><span class="detail-fact-label"><?= escape_html(tr('recent')) ?></span><span class="detail-fact-value"><?= (int) $book['published_year'] ?></span></div><?php endif; ?>
                <?php if (!empty($book['publisher'])): ?><div><span class="detail-fact-label"><?= escape_html(tr('publisher')) ?></span><span class="detail-fact-value"><?= escape_html($book['publisher']) ?></span></div><?php endif; ?>
                <?php if (!empty($book['page_count'])): ?><div><span class="detail-fact-label"><?= escape_html(tr('pages')) ?></span><span class="detail-fact-value"><?= (int) $book['page_count'] ?></span></div><?php endif; ?>
                <?php if (!empty($book['isbn13'])): ?><div><span class="detail-fact-label">ISBN</span><span class="detail-fact-value"><?= escape_html($book['isbn13']) ?></span></div><?php endif; ?>
            </div>

            <?php if ($book['categories'] !== []): ?>
                <div class="detail-categories">
                    <?php foreach ($book['categories'] as $category): ?>
                        <a class="category-chip" href="<?= escape_html(app_url('books/index.php?category=' . rawurlencode($category['slug']))) ?>"><?= escape_html($language === 'ckb' ? $category['name_ckb'] : $category['name_en']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($book['sale_price_iqd'] !== null): ?><p class="detail-price"><?= escape_html(format_iqd((int) $book['sale_price_iqd'])) ?></p><?php endif; ?>
            <div class="detail-stock">
                <?php if ((int) $book['sale_copies_available'] > 0): ?><span><?= (int) $book['sale_copies_available'] ?> <?= escape_html(tr('copies_for_sale')) ?></span><?php endif; ?>
                <?php if ((int) $book['loan_copies_available'] > 0): ?><span><?= (int) $book['loan_copies_available'] ?> <?= escape_html(tr('copies_to_borrow')) ?></span><?php endif; ?>
                <?php if ((int) $book['sale_copies_available'] === 0 && (int) $book['loan_copies_available'] === 0): ?><span><?= escape_html(tr('currently_unavailable')) ?></span><?php endif; ?>
            </div>

            <div class="detail-actions">
                <?php if ($book['sale_price_iqd'] !== null && (int) $book['sale_copies_available'] > 0): ?>
                    <form action="<?= escape_html(app_url('cart/add.php')) ?>" method="post">
                        <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">
                        <button class="button button-ink" type="submit"><?= escape_html(tr('add_to_cart')) ?></button>
                    </form>
                <?php endif; ?>
                <?php if ((int) $book['loan_copies_available'] > 0): ?>
                    <form action="<?= escape_html(app_url('loans/request.php')) ?>" method="post">
                        <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>"><input type="hidden" name="action" value="borrow">
                        <button class="button button-outline" type="submit"><?= escape_html(tr('to_borrow')) ?> · <?= escape_html(tr('borrow_days')) ?></button>
                    </form>
                <?php else: ?>
                    <form action="<?= escape_html(app_url('loans/request.php')) ?>" method="post">
                        <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>"><input type="hidden" name="action" value="reserve">
                        <button class="button button-outline" type="submit"><?= escape_html(tr('reserve')) ?></button>
                    </form>
                <?php endif; ?>
                <?php if (is_authenticated()): ?>
                    <form action="<?= escape_html(app_url('wishlist/toggle.php')) ?>" method="post">
                        <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">
                        <button class="button button-text" type="submit" aria-pressed="<?= $isSaved ? 'true' : 'false' ?>"><?= escape_html(tr($isSaved ? 'remove_from_wishlist' : 'add_to_wishlist')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <p class="form-help"><?= escape_html(tr('open_library_credit')) ?><?php if ($openLibraryPath !== null): ?> <a href="https://openlibrary.org/<?= $openLibraryPath ?>/<?= rawurlencode($openLibraryId) ?>" target="_blank" rel="noopener noreferrer">Open Library</a><?php endif; ?></p>
        </div>
    </section>

    <section class="page-wrap review-section" aria-labelledby="reviews-heading">
        <div class="section-heading">
            <div><p class="section-kicker"><?= escape_html(tr('reviews')) ?></p><h2 class="section-title" id="reviews-heading"><?= escape_html(tr('reviews')) ?></h2></div>
            <?php if (is_authenticated()): ?><a class="text-link" href="#review-form"><?= escape_html(tr('review')) ?><span aria-hidden="true">→</span></a><?php endif; ?>
        </div>
        <?php if ($reviews !== []): ?>
            <div class="review-list">
                <?php foreach ($reviews as $review): $reviewDate = date('Y-m-d', strtotime((string) $review['created_at'])); ?>
                    <article class="review-card">
                        <div class="review-meta"><span aria-label="<?= (int) $review['rating'] ?> <?= escape_html(tr('rating_label')) ?>"><?= str_repeat('★', (int) $review['rating']) ?></span> · <?= escape_html($review['first_name']) ?> · <time datetime="<?= escape_html($reviewDate) ?>" aria-label="<?= escape_html(sprintf(tr('reviewed_on'), $reviewDate)) ?>"><?= escape_html($reviewDate) ?></time></div>
                        <?php if (!empty($review['title'])): ?><h3><?= escape_html($review['title']) ?></h3><?php endif; ?>
                        <?php if (!empty($review['body'])): ?><p><?= escape_html($review['body']) ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state"><strong><?= escape_html(tr('nothing_here')) ?></strong><?= escape_html(tr('reviews')) ?></div>
        <?php endif; ?>
        <?php if (is_authenticated()): ?>
            <form class="form-card" id="review-form" action="<?= escape_html(app_url('reviews/create.php')) ?>" method="post">
                <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">
                <div class="form-field"><label for="review-rating"><?= escape_html(tr('rating')) ?></label><select id="review-rating" name="rating" required><option value="">—</option><?php for ($rating = 5; $rating >= 1; $rating--): ?><option value="<?= $rating ?>"><?= $rating ?> / 5</option><?php endfor; ?></select></div>
                <div class="form-field"><label for="review-title"><?= escape_html(tr('review_title')) ?></label><input id="review-title" name="title" maxlength="160"></div>
                <div class="form-field"><label for="review-body"><?= escape_html(tr('review_body')) ?></label><textarea id="review-body" name="body" maxlength="3000"></textarea></div>
                <button class="button button-ink" type="submit"><?= escape_html(tr('save_review')) ?></button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php render_footer(); ?>
