<?php
declare(strict_types=1);

function escape_html(mixed $value): string
{
    $text = is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_iqd(int $amount): string
{
    return 'IQD ' . number_format(max(0, $amount), 0, '.', ',');
}

function calculate_late_fine(int $overdueDays, int $dailyRate): int
{
    $days = max(0, $overdueDays);
    $rate = max(0, $dailyRate);
    if ($rate === 0 || $days === 0) {
        return 0;
    }

    return $days > intdiv(PHP_INT_MAX, $rate) ? PHP_INT_MAX : $days * $rate;
}

function cover_url(?string $openLibraryWorkId, ?string $isbn13): ?string
{
    $workId = strtoupper(trim((string) $openLibraryWorkId));
    if ($workId !== '' && preg_match('/^OL[0-9]+W$/', $workId) === 1) {
        return 'https://covers.openlibrary.org/b/olid/' . rawurlencode($workId) . '-L.jpg';
    }

    $isbn = preg_replace('/[-\s]/', '', (string) $isbn13) ?? '';
    if (preg_match('/^(?:[0-9]{13}|[0-9]{9}[0-9X])$/i', $isbn) === 1) {
        return 'https://covers.openlibrary.org/b/isbn/' . rawurlencode(strtoupper($isbn)) . '-L.jpg';
    }

    return null;
}

function is_staff_role(?string $role): bool
{
    return in_array($role, ['librarian', 'admin'], true);
}

function normalize_language(mixed $language): string
{
    return strtolower(trim((string) $language)) === 'ckb' ? 'ckb' : 'en';
}

function clamp_int(mixed $value, int $minimum, int $maximum, int $fallback): int
{
    $minimum = min($minimum, $maximum);
    $maximum = max($minimum, $maximum);
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    $candidate = $parsed === false ? $fallback : $parsed;

    return max($minimum, min($maximum, $candidate));
}

function current_language(): string
{
    $candidate = $_GET['lang'] ?? $_COOKIE['libris_lang'] ?? $_SESSION['preferred_lang'] ?? 'en';
    return normalize_language(is_string($candidate) ? $candidate : 'en');
}

function base_path(): string
{
    return rtrim((string) (getenv('LIBRIS_BASE_PATH') ?: ''), '/');
}

function app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return base_path() . ($path === '' ? '/' : '/' . $path);
}

function redirect_to(string $path): never
{
    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\r\n]/', $path)) {
        $path = app_url();
    }

    header('Location: ' . $path, true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return rotate_csrf_token();
    }

    return $_SESSION['csrf_token'];
}

function rotate_csrf_token(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . escape_html(csrf_token()) . '">';
}

function verify_csrf_token(mixed $submitted): bool
{
    return is_string($submitted)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

function set_flash(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function take_flash(string $key): ?string
{
    $message = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);

    return is_string($message) ? $message : null;
}

function is_authenticated(): bool
{
    return isset($_SESSION['user']['id']) && is_int($_SESSION['user']['id']);
}

function current_user(): ?array
{
    return is_authenticated() ? $_SESSION['user'] : null;
}

function refresh_authenticated_user(PDO $pdo): bool
{
    if (!is_authenticated()) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT id, email, first_name, last_name, role, preferred_lang
         FROM users WHERE id = :id AND is_active = 1'
    );
    $statement->execute(['id' => $_SESSION['user']['id']]);
    $user = $statement->fetch();
    if (!$user) {
        return false;
    }

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'email' => (string) $user['email'],
        'name' => trim($user['first_name'] . ' ' . $user['last_name']),
        'role' => (string) $user['role'],
    ];
    $_SESSION['preferred_lang'] = normalize_language($user['preferred_lang'] ?? 'en');

    return true;
}

function require_authentication(): void
{
    if (!is_authenticated()) {
        set_flash('error', tr('sign_in_to_continue'));
        redirect_to(app_url('auth/login.php'));
    }

    if (!refresh_authenticated_user(db())) {
        $_SESSION = [];
        session_regenerate_id(true);
        set_flash('error', tr('account_inactive'));
        redirect_to(app_url('auth/login.php'));
    }
}

function require_staff(): void
{
    require_authentication();
    if (!is_staff_role($_SESSION['user']['role'] ?? null)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function client_ip_binary(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $packed = is_string($ip) ? inet_pton($ip) : false;

    return $packed === false ? null : $packed;
}
