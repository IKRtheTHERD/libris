<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
render_header(tr('about'));
?>
<section class="page-wrap about-grid">
    <div class="about-copy">
        <p class="eyebrow"><?= escape_html(tr('about_eyebrow')) ?></p>
        <h1 class="page-title"><?= escape_html(tr('about_title')) ?></h1>
        <p><?= escape_html(tr('about_story')) ?></p>
        <p><?= escape_html(tr('about_library')) ?></p>
        <div class="hero-actions">
            <a class="button button-ink" href="<?= escape_html(app_url('books/index.php')) ?>"><?= escape_html(tr('browse')) ?></a>
            <a class="button button-outline" href="<?= escape_html(app_url('books/index.php?availability=loan')) ?>"><?= escape_html(tr('to_borrow')) ?></a>
        </div>
    </div>
    <div class="about-art"><img src="<?= escape_html(app_url('assets/images/book-placeholder.svg')) ?>" alt="<?= escape_html(tr('about_art_alt')) ?>" width="260" height="360"></div>
</section>
<?php render_footer(); ?>
