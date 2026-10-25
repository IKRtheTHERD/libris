<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/views.php';
require_once __DIR__ . '/../includes/layout.php';

require_authentication();
$items = [];
$total = 0;
$databaseUnavailable = false;
try {
    $statement = db()->prepare(
        'SELECT ci.id, ci.book_id, ci.quantity, b.title, b.cover_url, b.open_library_id, b.isbn13, b.sale_price_iqd,
                COALESCE((SELECT COUNT(*) FROM book_copies c WHERE c.book_id = b.id AND c.purpose = \'sale\' AND c.status = \'available\'), 0) AS available,
                (SELECT GROUP_CONCAT(a.name ORDER BY ba.author_order SEPARATOR \' · \') FROM book_authors ba JOIN authors a ON a.id = ba.author_id WHERE ba.book_id = b.id) AS authors
         FROM cart_items ci JOIN books b ON b.id = ci.book_id
         WHERE ci.user_id = :user_id ORDER BY ci.added_at DESC'
    );
    $statement->execute(['user_id' => (int) current_user()['id']]);
    $items = $statement->fetchAll();
    foreach ($items as $item) {
        $total += (int) $item['sale_price_iqd'] * (int) $item['quantity'];
    }
} catch (PDOException) {
    $databaseUnavailable = true;
    http_response_code(503);
}

render_header(tr('your_bag'));
?>
<section class="page-wrap page-heading">
    <nav class="breadcrumbs" aria-label="<?= escape_html(tr('breadcrumb')) ?>"><a href="<?= escape_html(app_url()) ?>">Libris</a><span aria-hidden="true">/</span><span><?= escape_html(tr('your_bag')) ?></span></nav>
    <p class="section-kicker"><?= escape_html(tr('books')) ?></p>
    <h1 class="page-title"><?= escape_html(tr('your_bag')) ?></h1>
</section>
<section class="page-wrap cart-layout">
    <?php if ($databaseUnavailable): ?>
        <div class="empty-state"><strong><?= escape_html(tr('curated_shelf')) ?></strong><?= escape_html(tr('nothing_here')) ?></div>
    <?php elseif ($items === []): ?>
        <div class="empty-state"><strong><?= escape_html(tr('empty_bag')) ?></strong><a class="text-link" href="<?= escape_html(app_url('books/index.php')) ?>"><?= escape_html(tr('browse')) ?><span aria-hidden="true">→</span></a></div>
    <?php else: ?>
        <div class="cart-items">
            <?php foreach ($items as $item): ?>
                <article class="cart-item">
                    <a class="cart-cover" href="<?= escape_html(app_url('books/show.php?id=' . (int) $item['book_id'])) ?>"><img src="<?= escape_html(book_cover_source($item)) ?>" alt="" width="110" height="150" loading="lazy" data-book-cover></a>
                    <div class="cart-item-copy">
                        <h2><a href="<?= escape_html(app_url('books/show.php?id=' . (int) $item['book_id'])) ?>"><?= escape_html($item['title']) ?></a></h2>
                        <?php if ($item['authors']): ?><p class="book-card-author"><?= escape_html(tr('by')) ?> <?= escape_html($item['authors']) ?></p><?php endif; ?>
                        <p class="cart-price"><?= escape_html(format_iqd((int) $item['sale_price_iqd'])) ?></p>
                        <?php if ((int) $item['available'] < (int) $item['quantity']): ?><p class="cart-warning"><?= escape_html(tr('currently_unavailable')) ?></p><?php endif; ?>
                        <form class="cart-item-actions" action="<?= escape_html(app_url('cart/update.php')) ?>" method="post">
                            <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int) $item['book_id'] ?>">
                            <label for="quantity-<?= (int) $item['id'] ?>"><?= escape_html(tr('quantity')) ?></label>
                            <input id="quantity-<?= (int) $item['id'] ?>" type="number" name="quantity" value="<?= (int) $item['quantity'] ?>" min="1" max="20">
                            <button class="button button-text" type="submit"><?= escape_html(tr('save_changes')) ?></button>
                        </form>
                    </div>
                    <div class="cart-item-total"><?= escape_html(format_iqd((int) $item['sale_price_iqd'] * (int) $item['quantity'])) ?></div>
                    <form action="<?= escape_html(app_url('cart/remove.php')) ?>" method="post">
                        <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int) $item['book_id'] ?>">
                        <button class="button button-text" type="submit" aria-label="<?= escape_html(tr('remove')) ?>">×</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
        <aside class="cart-summary">
            <h2 class="serif"><?= escape_html(tr('subtotal')) ?></h2>
            <p class="cart-summary-total"><?= escape_html(format_iqd($total)) ?></p>
            <p class="form-help"><?= escape_html(tr('simulated_payment')) ?></p>
            <a class="button button-ink" href="<?= escape_html(app_url('checkout.php')) ?>"><?= escape_html(tr('checkout')) ?><span class="button-arrow" aria-hidden="true">→</span></a>
        </aside>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
