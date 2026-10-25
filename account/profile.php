<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_authentication();
require_once __DIR__ . '/../includes/db.php';
$pdo = db();
$userId = (int) current_user()['id'];
$statement = $pdo->prepare('SELECT first_name, last_name, email, preferred_lang FROM users WHERE id = :id AND is_active = 1');
$statement->execute(['id' => $userId]);
$profile = $statement->fetch();
if (!$profile) {
    sign_out();
    redirect_to(app_url('auth/login.php'));
}
$error = null;
$saved = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();
    $firstName = trim(is_string($_POST['first_name'] ?? null) ? $_POST['first_name'] : '');
    $lastName = trim(is_string($_POST['last_name'] ?? null) ? $_POST['last_name'] : '');
    $language = $_POST['preferred_lang'] ?? null;
    if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100 || !in_array($language, ['en', 'ckb'], true)) {
        $error = tr('profile_invalid');
    } else {
        $update = $pdo->prepare('UPDATE users SET first_name = :first_name, last_name = :last_name, preferred_lang = :language WHERE id = :id AND is_active = 1');
        $update->execute(['first_name' => $firstName, 'last_name' => $lastName, 'language' => $language, 'id' => $userId]);
        record_activity($pdo, $userId, 'account.profile_update', 'user', $userId);
        $_SESSION['user']['name'] = $firstName . ' ' . $lastName;
        $_SESSION['preferred_lang'] = $language;
        $_COOKIE['libris_lang'] = $language;
        setcookie('libris_lang', $language, [
            'expires' => time() + 31536000,
            'path' => base_path() === '' ? '/' : base_path() . '/',
            'secure' => isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $profile['first_name'] = $firstName;
        $profile['last_name'] = $lastName;
        $profile['preferred_lang'] = $language;
        $saved = true;
    }
}

require_once __DIR__ . '/../includes/layout.php';
render_header(tr('profile'), 'account');
?>
<section class="page-wrap account-layout">
    <nav class="account-nav" aria-label="<?= escape_html(tr('account')) ?>">
        <a href="<?= escape_html(app_url('account/index.php')) ?>"><?= escape_html(tr('account_overview')) ?></a>
        <a class="is-active" href="<?= escape_html(app_url('account/profile.php')) ?>"><?= escape_html(tr('profile')) ?></a>
        <a href="<?= escape_html(app_url('account/addresses.php')) ?>"><?= escape_html(tr('saved_addresses')) ?></a>
        <a href="<?= escape_html(app_url('orders/index.php')) ?>"><?= escape_html(tr('orders')) ?></a>
        <a href="<?= escape_html(app_url('account/loans.php')) ?>"><?= escape_html(tr('my_loans')) ?></a>
    </nav>
    <div class="account-content">
        <p class="eyebrow"><?= escape_html(tr('account_eyebrow')) ?></p>
        <h1><?= escape_html(tr('profile')) ?></h1>
        <?php if ($saved): ?><p class="flash flash-success" role="status"><?= escape_html(tr('changes_saved')) ?></p><?php endif; ?>
        <?php if ($error !== null): ?><p class="form-error" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
        <section class="account-card">
            <form method="post" action="<?= escape_html(app_url('account/profile.php')) ?>">
                <?= csrf_field() ?>
                <div class="form-field"><label for="first-name"><?= escape_html(tr('first_name')) ?></label><input id="first-name" name="first_name" maxlength="100" autocomplete="given-name" required value="<?= escape_html($profile['first_name']) ?>"></div>
                <div class="form-field"><label for="last-name"><?= escape_html(tr('last_name')) ?></label><input id="last-name" name="last_name" maxlength="100" autocomplete="family-name" required value="<?= escape_html($profile['last_name']) ?>"></div>
                <div class="form-field"><label for="email"><?= escape_html(tr('email')) ?></label><input id="email" type="email" value="<?= escape_html($profile['email']) ?>" readonly></div>
                <div class="form-field"><label for="preferred-lang"><?= escape_html(tr('preferred_language')) ?></label><select id="preferred-lang" name="preferred_lang"><option value="en"<?= $profile['preferred_lang'] === 'en' ? ' selected' : '' ?>>English</option><option value="ckb"<?= $profile['preferred_lang'] === 'ckb' ? ' selected' : '' ?>>کوردی سۆرانی</option></select></div>
                <button class="button button-ink" type="submit"><?= escape_html(tr('save_changes')) ?></button>
            </form>
        </section>
    </div>
</section>
<?php render_footer(); ?>
