<?php
declare(strict_types=1);

function book_cover_source(array $book): string
{
    $provided = (string) ($book['cover_url'] ?? '');
    $host = parse_url($provided, PHP_URL_HOST);
    if (parse_url($provided, PHP_URL_SCHEME) === 'https' && $host === 'covers.openlibrary.org') {
        return $provided;
    }

    return cover_url($book['open_library_id'] ?? null, $book['isbn13'] ?? null)
        ?? app_url('assets/images/book-placeholder.svg');
}

function render_book_card(array $book, ?string $language = null): void
{
    $language = normalize_language($language ?? current_language());
    $price = $book['sale_price_iqd'] === null ? null : format_iqd((int) $book['sale_price_iqd']);
    $authorLine = trim((string) ($book['authors'] ?? ''));
    $available = (int) ($book['sale_copies_available'] ?? 0) + (int) ($book['loan_copies_available'] ?? 0);
    ?>
<article class="book-card" data-reveal>
    <a class="book-card-cover" href="<?= escape_html(app_url('books/show.php?id=' . (int) $book['id'])) ?>" tabindex="-1" aria-hidden="true">
        <img src="<?= escape_html(book_cover_source($book)) ?>" alt="" loading="lazy" decoding="async" width="480" height="680" data-book-cover>
        <span class="cover-shadow"></span>
    </a>
    <div class="book-card-copy">
        <div class="book-card-meta">
            <?php if (!empty($book['published_year'])): ?><span><?= escape_html((string) $book['published_year']) ?></span><?php endif; ?>
            <?php if ((int) ($book['review_count'] ?? 0) > 0): ?><span class="rating-mini"><span aria-hidden="true">★</span> <?= number_format((float) $book['average_rating'], 1) ?></span><?php endif; ?>
        </div>
        <h3><a href="<?= escape_html(app_url('books/show.php?id=' . (int) $book['id'])) ?>"><?= escape_html($book['title']) ?></a></h3>
        <?php if ($authorLine !== ''): ?><p class="book-card-author"><?= escape_html(tr('by', $language)) ?> <?= escape_html($authorLine) ?></p><?php endif; ?>
        <div class="book-card-bottom">
            <span class="book-card-price"><?= $price === null ? escape_html(tr('currently_unavailable', $language)) : escape_html($price) ?></span>
            <?php if ($available > 0): ?><span class="availability-dot" aria-label="<?= escape_html(tr('in_stock', $language)) ?>"></span><?php endif; ?>
        </div>
    </div>
</article>
<?php
}
