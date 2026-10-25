<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$error = null;
$fieldErrors = [];
$values = [
    'first_name' => is_string($_POST['first_name'] ?? null) ? trim($_POST['first_name']) : '',
    'last_name' => is_string($_POST['last_name'] ?? null) ? trim($_POST['last_name']) : '',
    'email' => is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '',
];
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();

    try {
        if ($password !== $confirmation) {
            throw new InvalidArgumentException(tr('error_password_mismatch'));
        }

        $userId = register_customer(db(), $values['email'], $values['first_name'], $values['last_name'], $password);
        establish_user_session(db(), $userId);
        redirect_to(app_url('account/index.php'));
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
        $validationFields = [
            'error_invalid_email' => 'email',
            'error_name_required' => $values['first_name'] === '' ? 'first_name' : 'last_name',
            'error_password_length' => 'password',
            'error_email_exists' => 'email',
            'error_password_mismatch' => 'password_confirmation',
        ];

        foreach ($validationFields as $messageKey => $field) {
            if ($error === tr($messageKey)) {
                $fieldErrors[$field] = $error;
                break;
            }
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $error = tr('error_generic');
    }
}

require_once __DIR__ . '/../includes/layout.php';
render_header(tr('register'));
$describedBy = static function (string $field) use ($fieldErrors): string {
    $descriptions = match ($field) {
        'email' => ['email-hint'],
        'password' => ['password-hint'],
        'password_confirmation' => ['password-confirmation-hint'],
        default => [],
    };
    if (isset($fieldErrors[$field])) {
        $descriptions[] = $field . '-error';
    }

    $attributes = $descriptions === [] ? '' : ' aria-describedby="' . escape_html(implode(' ', $descriptions)) . '"';
    if (isset($fieldErrors[$field])) {
        $attributes .= ' aria-invalid="true"';
    }

    return $attributes;
};
?>
<section class="page-wrap form-page">
    <div class="form-card">
        <p class="eyebrow"><?= escape_html(tr('membership_eyebrow')) ?></p>
        <h1><?= escape_html(tr('register')) ?></h1>
        <p class="form-error" id="register-error-summary" role="alert" tabindex="-1" data-register-error-summary
            data-dismissible="<?= $error === null || $fieldErrors !== [] ? 'true' : 'false' ?>"<?= $error === null ? ' hidden' : '' ?>><?= escape_html($error ?? '') ?></p>
        <form method="post" action="<?= escape_html(app_url('auth/register.php')) ?>" data-register-form
            data-required-message="<?= escape_html(tr('required_fields')) ?>"
            data-email-message="<?= escape_html(tr('error_invalid_email')) ?>"
            data-password-message="<?= escape_html(tr('error_password_length')) ?>"
            data-password-mismatch-message="<?= escape_html(tr('error_password_mismatch')) ?>">
            <?= csrf_field() ?>

            <div class="form-field">
                <label for="first-name"><?= escape_html(tr('first_name')) ?></label>
                <input id="first-name" name="first_name" type="text" maxlength="100" autocomplete="given-name"
                    required aria-required="true"<?= $describedBy('first_name') ?>
                    value="<?= escape_html($values['first_name']) ?>">
                <?php if (isset($fieldErrors['first_name'])): ?>
                    <p class="form-error" id="first_name-error" data-field-error="first_name" aria-live="polite"><?= escape_html($fieldErrors['first_name']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="last-name"><?= escape_html(tr('last_name')) ?></label>
                <input id="last-name" name="last_name" type="text" maxlength="100" autocomplete="family-name"
                    required aria-required="true"<?= $describedBy('last_name') ?>
                    value="<?= escape_html($values['last_name']) ?>">
                <?php if (isset($fieldErrors['last_name'])): ?>
                    <p class="form-error" id="last_name-error" data-field-error="last_name" aria-live="polite"><?= escape_html($fieldErrors['last_name']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="email"><?= escape_html(tr('email')) ?></label>
                <input id="email" name="email" type="email" maxlength="190" autocomplete="email"
                    required aria-required="true"<?= $describedBy('email') ?>
                    value="<?= escape_html($values['email']) ?>" data-hint-id="email-hint">
                <p class="form-hint" id="email-hint"><?= escape_html(tr('error_invalid_email')) ?></p>
                <?php if (isset($fieldErrors['email'])): ?>
                    <p class="form-error" id="email-error" data-field-error="email" aria-live="polite"><?= escape_html($fieldErrors['email']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="password"><?= escape_html(tr('password')) ?></label>
                <input id="password" name="password" type="password" minlength="12" maxlength="200"
                    autocomplete="new-password" required aria-required="true"<?= $describedBy('password') ?>
                    data-hint-id="password-hint" data-password-field>
                <p class="form-hint" id="password-hint"><?= escape_html(tr('error_password_length')) ?></p>
                <?php if (isset($fieldErrors['password'])): ?>
                    <p class="form-error" id="password-error" data-field-error="password" aria-live="polite"><?= escape_html($fieldErrors['password']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="password-confirmation"><?= escape_html(tr('confirm_password')) ?></label>
                <input id="password-confirmation" name="password_confirmation" type="password" minlength="12" maxlength="200"
                    autocomplete="new-password" required aria-required="true"<?= $describedBy('password_confirmation') ?>
                    data-hint-id="password-confirmation-hint" data-password-confirmation>
                <p class="form-hint" id="password-confirmation-hint"><?= escape_html(tr('error_password_mismatch')) ?></p>
                <?php if (isset($fieldErrors['password_confirmation'])): ?>
                    <p class="form-error" id="password_confirmation-error" data-field-error="password_confirmation" aria-live="polite"><?= escape_html($fieldErrors['password_confirmation']) ?></p>
                <?php endif; ?>
            </div>

            <button class="button button-ink" type="submit"><?= escape_html(tr('register')) ?></button>
        </form>
        <p class="form-switch"><?= escape_html(tr('have_account')) ?>
            <a href="<?= escape_html(app_url('auth/login.php')) ?>"><?= escape_html(tr('sign_in')) ?></a>
        </p>
    </div>
</section>
<script src="<?= escape_html(app_url('assets/js/register-validation.js')) ?>" defer></script>
<?php render_footer(); ?>
