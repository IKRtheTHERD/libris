<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/i18n.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$page = file_get_contents(__DIR__ . '/../../auth/register.php');
$script = file_get_contents(__DIR__ . '/../../assets/js/register-validation.js');
$assert(is_string($page) && is_string($script), 'Registration view and validation script must be readable.');

foreach (['first-name', 'last-name', 'email', 'password', 'password-confirmation'] as $id) {
    $assert(str_contains($page, 'for="' . $id . '"'), "Registration field {$id} must have an explicit label.");
    $assert(str_contains($page, 'id="' . $id . '"'), "Registration field {$id} must have a stable ID.");
}
$assert(substr_count($page, 'aria-required="true"') === 5, 'All five registration inputs must expose required state.');
$assert(str_contains($page, 'aria-describedby') && str_contains($page, 'aria-invalid="true"'), 'Server validation must associate field errors and invalid state.');
$assert(str_contains($page, 'role="alert"') && str_contains($page, 'data-register-error-summary'), 'The page must provide a focusable error summary.');
$assert(str_contains($page, 'data-field-error') && str_contains($page, 'aria-live="polite"'), 'Field-level errors must be announced.');
$assert(str_contains($page, 'data-required-message=') && str_contains($page, 'data-password-mismatch-message='), 'Validation feedback must use the active server-rendered locale.');
$assert(str_contains($script, "field.focus()") && str_contains($script, 'setCustomValidity'), 'Client validation must focus invalid fields and provide localized validity text.');
$assert(str_contains($script, "field.setAttribute('aria-invalid', 'true')"), 'Client validation must expose invalid state programmatically.');
$assert(str_contains($script, 'aria-describedby'), 'Client validation must connect each error to its input.');

foreach (['required_fields', 'error_invalid_email', 'error_password_length', 'error_password_mismatch'] as $key) {
    $assert(isset(LIBRIS_TRANSLATIONS['en'][$key]), "English validation copy {$key} must exist.");
    $assert(isset(LIBRIS_TRANSLATIONS['ckb'][$key]), "Sorani validation copy {$key} must exist.");
}

fwrite(STDOUT, "{$checks} registration accessibility checks passed.\n");
