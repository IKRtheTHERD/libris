<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/i18n.php';

function render_header(string $pageTitle, string $active = ''): void
{
    $language = current_language();
    $direction = $language === 'ckb' ? 'rtl' : 'ltr';
    $user = current_user();
    $title = $pageTitle === '' ? 'Libris' : $pageTitle . ' · Libris';
    ?>
<!doctype html>
<html lang="<?= escape_html($language) ?>" dir="<?= $direction ?>" data-base-path="<?= escape_html(base_path()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f0e7">
    <title><?= escape_html($title) ?></title>
    <link rel="preconnect" href="https://covers.openlibrary.org" crossorigin>
    <link rel="stylesheet" href="<?= escape_html(app_url('assets/css/style.css')) ?>">
    <script src="<?= escape_html(app_url('assets/js/i18n.js')) ?>" defer></script>
    <script src="<?= escape_html(app_url('assets/js/main.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content"><?= escape_html(tr('skip_to_content')) ?></a>
<div class="announcement"><?= escape_html(tr('tagline')) ?></div>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="<?= escape_html(app_url()) ?>" aria-label="<?= escape_html(tr('libris_home')) ?>">
            <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
            <span class="brand-name">libris</span>
        </a>
        <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="primary-nav" data-menu-toggle>
            <span class="sr-only"><?= escape_html(tr('open_menu')) ?></span><span class="menu-lines" aria-hidden="true"></span>
        </button>
        <nav class="primary-nav" id="primary-nav" aria-label="<?= escape_html(tr('primary_navigation')) ?>">
            <a class="nav-link<?= $active === 'discover' ? ' is-active' : '' ?>" href="<?= escape_html(app_url('books/index.php')) ?>"><?= escape_html(tr('discover')) ?></a>
            <a class="nav-link<?= $active === 'library' ? ' is-active' : '' ?>" href="<?= escape_html(app_url('books/index.php?availability=loan')) ?>"><?= escape_html(tr('library')) ?></a>
            <a class="nav-link<?= $active === 'about' ? ' is-active' : '' ?>" href="<?= escape_html(app_url('about.php')) ?>"><?= escape_html(tr('about')) ?></a>
<?php if ($user): ?>
<a class="nav-link compact-nav-item" href="<?= escape_html(app_url('account/index.php')) ?>"><?= escape_html(tr('account')) ?></a>
<?php if (is_staff_role($user['role'] ?? null)): ?>
<a class="nav-link compact-nav-item" href="<?= escape_html(app_url('admin/index.php')) ?>"><?= escape_html(tr('staff')) ?></a>
<?php endif; ?>
<form class="compact-nav-form" action="<?= escape_html(app_url('auth/logout.php')) ?>" method="post">
<?= csrf_field() ?><button class="nav-link compact-nav-action" type="submit"><?= escape_html(tr('sign_out')) ?></button>
</form>
<?php else: ?>
<a class="nav-link compact-nav-item" href="<?= escape_html(app_url('auth/login.php')) ?>"><?= escape_html(tr('sign_in')) ?></a>
<a class="nav-link compact-nav-item" href="<?= escape_html(app_url('auth/register.php')) ?>"><?= escape_html(tr('join')) ?></a>
<?php endif; ?>
        </nav>
        <div class="header-actions">
            <button class="language-toggle" type="button" data-language-toggle aria-label="<?= escape_html(tr('switch_language')) ?>">
                <span class="language-globe" aria-hidden="true">◌</span><span><?= escape_html(tr('language_name')) ?></span>
            </button>
<button class="theme-toggle" type="button" data-theme-toggle aria-label="<?= escape_html(tr('theme')) ?>" aria-pressed="false" title="<?= escape_html(tr('theme')) ?>">
                <span aria-hidden="true">◐</span>
            </button>
            <?php if ($user): ?>
                <a class="header-account" href="<?= escape_html(app_url('account/index.php')) ?>"><?= escape_html(tr('account')) ?></a>
                <?php if (is_staff_role($user['role'] ?? null)): ?>
                    <a class="header-icon-link" href="<?= escape_html(app_url('admin/index.php')) ?>" aria-label="<?= escape_html(tr('staff')) ?>">⌂</a>
                <?php endif; ?>
                <form class="inline-form" action="<?= escape_html(app_url('auth/logout.php')) ?>" method="post">
                    <?= csrf_field() ?><button class="header-signin" type="submit"><?= escape_html(tr('sign_out')) ?></button>
                </form>
            <?php else: ?>
                <a class="header-signin" href="<?= escape_html(app_url('auth/login.php')) ?>"><?= escape_html(tr('sign_in')) ?></a>
                <a class="button button-small button-ink" href="<?= escape_html(app_url('auth/register.php')) ?>"><?= escape_html(tr('join')) ?></a>
            <?php endif; ?>
        </div>
    </div>
</header>
<main id="main-content">
<?php
    $success = take_flash('success');
    $error = take_flash('error');
    if ($success !== null || $error !== null): ?>
    <div class="flash-wrap page-wrap" aria-live="polite">
        <?php if ($success !== null): ?><p class="flash flash-success"><?= escape_html($success) ?></p><?php endif; ?>
        <?php if ($error !== null): ?><p class="flash flash-error"><?= escape_html($error) ?></p><?php endif; ?>
    </div>
    <?php endif;
}

function render_footer(): void
{
    ?>
</main>
<footer class="site-footer">
    <div class="footer-inner">
        <a class="brand brand-footer" href="<?= escape_html(app_url()) ?>">
            <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
            <span class="brand-name">libris</span>
        </a>
        <p><?= escape_html(tr('site_footer')) ?></p>
        <div class="footer-meta">
            <span>© <?= date('Y') ?> Libris</span>
            <span><?= escape_html(tr('open_library_credit')) ?></span>
        </div>
    </div>
</footer>
</body>
</html>
<?php
}
