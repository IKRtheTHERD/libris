<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_authentication();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/views.php';
$statement = db()->prepare(
    'SELECT b.id, b.title, b.cover_url, b.open_library_id, b.isbn13, b.sale_price_iqd, w.created_at
     FROM wishlist w JOIN books b ON b.id = w.book_id
     WHERE w.user_id = :user_id AND b.is_active = 1
     ORDER BY w.created_at DESC'
);
$statement->execute(['user_id' => (int) current_user()['id']]);
$books = $statement->fetchAll();
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('wishlist'), 'account');
?>
<section class="page-wrap account-layout">
    <nav class="account-nav" aria-label="<?= escape_html(tr('account')) ?>">
        <a href="<?= escape_html(app_url('account/index.php')) ?>"><?= escape_html(tr('account_overview')) ?></a>
        <a href="<?= escape_html(app_url('account/loans.php')) ?>"><?= escape_html(tr('my_loans')) ?></a>
        <a href="<?= escape_html(app_url('account/reservations.php')) ?>"><?= escape_html(tr('reservations')) ?></a>
        <a class="is-active" href="<?= escape_html(app_url('account/wishlist.php')) ?>"><?= escape_html(tr('wishlist')) ?></a>
        <a href="<?= escape_html(app_url('account/addresses.php')) ?>"><?= escape_html(tr('profile')) ?></a>
    </nav>
    <div class="account-content">
        <p class="eyebrow"><?= escape_html(tr('account_eyebrow')) ?></p>
        <h1><?= escape_html(tr('wishlist')) ?></h1>
        <?php if ($books === []): ?>
            <section class="account-card"><p><?= escape_html(tr('empty_wishlist')) ?></p><a class="button button-outline" href="<?= escape_html(app_url('books/index.php')) ?>"><?= escape_html(tr('browse')) ?></a></section>
        <?php else: ?>
            <div class="book-grid">
                <?php foreach ($books as $book): ?>
                    <article class="book-card">
                        <a class="book-card-cover" href="<?= escape_html(app_url('books/show.php?id=' . (int) $book['id'])) ?>">
                            <img src="<?= escape_html(book_cover_source($book)) ?>" alt="" loading="lazy" decoding="async" width="480" height="680" data-book-cover>
                        </a>
                        <div class="book-card-copy">
                            <h2 class="book-card-title"><a href="<?= escape_html(app_url('books/show.php?id=' . (int) $book['id'])) ?>"><?= escape_html($book['title']) ?></a></h2>
                            <p class="book-card-author"><?= $book['sale_price_iqd'] === null ? escape_html(tr('not_for_sale')) : escape_html(format_iqd((int) $book['sale_price_iqd'])) ?></p>
                            <form method="post" action="<?= escape_html(app_url('wishlist/toggle.php')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>"><input type="hidden" name="return_to" value="wishlist">
                                <button class="button-text" type="submit"><?= escape_html(tr('remove_from_wishlist')) ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php render_footer(); ?>
