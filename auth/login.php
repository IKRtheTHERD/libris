<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/login_rate_limit.php';

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();
    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    try {
 if (sign_in_with_rate_limit(db(), $email, $password)) {
            redirect_to(app_url('account/index.php'));
        }
        $error = tr('invalid_credentials');
 } catch (PDOException|RuntimeException $exception) {
        error_log($exception->getMessage());
        $error = tr('error_generic');
    }
}

require_once __DIR__ . '/../includes/layout.php';
render_header(tr('sign_in'));
?>
<section class="page-wrap form-page">
    <div class="form-card">
        <p class="eyebrow"><?= escape_html(tr('membership_eyebrow')) ?></p>
        <h1><?= escape_html(tr('sign_in')) ?></h1>
        <p class="form-intro"><?= escape_html(tr('sign_in_intro')) ?></p>
        <?php if ($error !== null): ?><p class="form-error" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
        <form method="post" action="<?= escape_html(app_url('auth/login.php')) ?>">
            <?= csrf_field() ?>
            <div class="form-field"><label for="email"><?= escape_html(tr('email')) ?></label>
            <input id="email" name="email" type="email" maxlength="190" autocomplete="email" required value="<?= escape_html($_POST['email'] ?? '') ?>"></div>
            <div class="form-field"><label for="password"><?= escape_html(tr('password')) ?></label>
            <input id="password" name="password" type="password" autocomplete="current-password" required></div>
            <button class="button button-ink" type="submit"><?= escape_html(tr('sign_in')) ?></button>
        </form>
        <p class="form-switch"><?= escape_html(tr('need_account')) ?> <a href="<?= escape_html(app_url('auth/register.php')) ?>"><?= escape_html(tr('join')) ?></a></p>
    </div>
</section>
<?php render_footer(); ?>
