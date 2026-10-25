<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/library.php';

$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

check(escape_html('<script>') === '&lt;script&gt;', 'HTML output must be escaped.');
check(function_exists('tr'), 'Authenticated routes must load translations before handling POST actions.');
check(tr('copy_available_to_borrow', 'en') === 'A copy is available to borrow now.', 'English reserve-action feedback must be available.');
check(tr('copy_available_to_borrow', 'ckb') === 'دانەیەک ئێستا بەردەستە بۆ خواستن.', 'Sorani reserve-action feedback must be available.');
foreach ([
    'error_title_not_for_sale', 'error_sale_quantity_limit', 'error_loan_action', 'error_account_inactive',
    'error_active_loan_limit', 'error_title_unavailable', 'error_reserved_copy_unavailable',
    'error_reservation_priority', 'error_no_loan_copies', 'error_already_waiting', 'error_copy_checked_out',
    'error_loan_not_found', 'error_cart_quantity_limit', 'error_title_not_in_bag', 'error_address_required',
    'error_bag_empty', 'error_bag_not_for_sale', 'error_sale_stock_changed', 'error_order_total_limit',
    'error_sale_copy_changed', 'error_invalid_address', 'error_address_not_owned', 'error_address_fields',
    'error_invalid_email', 'error_name_required', 'error_password_length', 'error_email_exists',
    'error_password_mismatch', 'account_inactive', 'invalid_credentials', 'error_generic', 'sign_in_to_continue',
] as $errorKey) {
    check(isset(LIBRIS_TRANSLATIONS['en'][$errorKey]), "English translation must exist for {$errorKey}.");
    check(isset(LIBRIS_TRANSLATIONS['ckb'][$errorKey]), "Sorani translation must exist for {$errorKey}.");
}
check(tr('error_title_unavailable', 'en') === 'This title is no longer available.', 'Known domain errors must resolve in English.');
check(tr('error_title_unavailable', 'ckb') !== '', 'Known domain errors must resolve in Sorani.');
check(escape_html(['malformed']) === '', 'Array form input must not leak conversion warnings into HTML.');
check(format_iqd(25000) === 'IQD 25,000', 'IQD formatting must use grouped digits.');
check(calculate_late_fine(3, 1000) === 3000, 'Late fine must multiply full overdue days by the daily rate.');
check(calculate_late_fine(-2, 1000) === 0, 'Negative overdue days must not create a fine.');
check(cover_url('OL123W', null) === 'https://covers.openlibrary.org/b/olid/OL123W-L.jpg', 'Cover URLs must use a public Open Library identifier URL.');
check(cover_url(null, '9780140328721') === 'https://covers.openlibrary.org/b/isbn/9780140328721-L.jpg', 'ISBN cover URLs must use the official public endpoint.');
check(cover_url(null, null) === null, 'Missing identifiers must use the local fallback cover.');
check(is_staff_role('librarian') && is_staff_role('admin'), 'Staff roles must have management access.');
check(!is_staff_role('customer'), 'Customer accounts must not have management access.');
$interfaceKeys = [
    'skip_to_content', 'libris_home', 'open_menu', 'primary_navigation', 'switch_language',
    'hero_art_alt', 'hero_established', 'hero_city', 'hero_book_kicker',
    'hero_book_title_first', 'hero_book_title_second', 'hero_book_author', 'breadcrumb',
    'setup_catalogue_help', 'catalogue_search_setup',
];
check(
    array_diff($interfaceKeys, array_keys(LIBRIS_TRANSLATIONS['en'])) === []
        && array_diff($interfaceKeys, array_keys(LIBRIS_TRANSLATIONS['ckb'])) === [],
    'Shared navigation, homepage art, and setup copy must exist in both languages.'
);
$reserveRejected = false;
try {
    assert_reservation_action_needs_queue('reserve', ['id' => 1]);
} catch (DomainException) {
    $reserveRejected = true;
}
check($reserveRejected, 'Reserve actions must not silently borrow an available copy.');
assert_reservation_action_needs_queue('reserve', null);
check(true, 'Readers may reserve when no copy is available.');
$sessionDb = new PDO('sqlite::memory:');
$sessionDb->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT, role TEXT, preferred_lang TEXT, is_active INTEGER)');
$sessionDb->exec("INSERT INTO users VALUES (1, 'member@example.test', 'Sam', 'Reader', 'customer', 'ckb', 1)");
$_SESSION = ['user' => ['id' => 1, 'email' => 'old@example.test', 'name' => 'Old Name', 'role' => 'admin']];
check(refresh_authenticated_user($sessionDb), 'Active accounts must refresh their current session identity.');
check($_SESSION['user']['role'] === 'customer' && $_SESSION['preferred_lang'] === 'ckb', 'Role changes and language preferences must refresh before authorization.');
$sessionDb->exec('UPDATE users SET is_active = 0 WHERE id = 1');
check(!refresh_authenticated_user($sessionDb), 'Deactivated accounts must fail the protected-session refresh.');
$_SESSION = [];
check(normalize_language('ckb') === 'ckb' && normalize_language('fr') === 'en', 'Unsupported languages must fall back to English.');
check(clamp_int('7', 1, 24, 1) === 7 && clamp_int('-4', 1, 24, 1) === 1, 'Numeric query values must be safely bounded.');

require_once __DIR__ . '/../../includes/i18n.php';
$_COOKIE['libris_lang'] = 'ckb';
check(tr('register') === 'هەژمارێک دروست بکە', 'Server-rendered translations must follow the saved Sorani cookie.');
$_COOKIE['libris_lang'] = 'en';
check(tr('register') === 'Create your account', 'Readers must be able to switch back to English.');

fwrite(STDOUT, "{$checks} domain checks passed.\n");
