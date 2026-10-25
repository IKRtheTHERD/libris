<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/i18n.php';

const AUTH_PASSWORD_PREHASH_PREFIX = 'libris-sha256-v1$';

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed.');
    }
}

function require_valid_csrf(): void
{
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Your session expired. Refresh the page and try again.');
    }
}

function record_activity(PDO $pdo, ?int $actorId, string $action, ?string $entityType = null, ?int $entityId = null, array $details = []): void
{
    $statement = $pdo->prepare(
        'INSERT INTO activity_log (actor_user_id, action, entity_type, entity_id, details, ip_address)
         VALUES (:actor, :action, :entity_type, :entity_id, :details, :ip)'
    );
    $statement->execute([
        'actor' => $actorId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
        'ip' => client_ip_binary(),
    ]);
}

function hash_auth_password(string $password): string
{
    $prehashed = base64_encode(hash('sha256', $password, true));

    return AUTH_PASSWORD_PREHASH_PREFIX . password_hash($prehashed, PASSWORD_DEFAULT);
}

function verify_auth_password(string $password, string $storedHash): bool
{
    if (str_starts_with($storedHash, AUTH_PASSWORD_PREHASH_PREFIX)) {
        $password = base64_encode(hash('sha256', $password, true));
        $storedHash = substr($storedHash, strlen(AUTH_PASSWORD_PREHASH_PREFIX));
    }

    return password_verify($password, $storedHash);
}

function sign_in(PDO $pdo, string $email, string $password): bool
{
    $statement = $pdo->prepare(
        'SELECT id, email, password_hash, first_name, last_name, role, preferred_lang
         FROM users WHERE email = :email AND is_active = 1 LIMIT 1'
    );
    $statement->execute(['email' => strtolower(trim($email))]);
    $user = $statement->fetch();
    if (!$user || !verify_auth_password($password, (string) $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    rotate_csrf_token();
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'email' => (string) $user['email'],
        'name' => trim($user['first_name'] . ' ' . $user['last_name']),
        'role' => (string) $user['role'],
    ];
    $_SESSION['preferred_lang'] = normalize_language($user['preferred_lang'] ?? 'en');
    record_activity($pdo, (int) $user['id'], 'auth.sign_in', 'user', (int) $user['id']);

    return true;
}

function register_customer(PDO $pdo, string $email, string $firstName, string $lastName, string $password): int
{
    $email = strtolower(trim($email));
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
        throw new InvalidArgumentException(tr('error_invalid_email'));
    }
    if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
        throw new InvalidArgumentException(tr('error_name_required'));
    }
    if (strlen($password) < 12 || strlen($password) > 200) {
        throw new InvalidArgumentException(tr('error_password_length'));
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (email, password_hash, first_name, last_name, preferred_lang)
         VALUES (:email, :password_hash, :first_name, :last_name, :preferred_lang)'
    );
    try {
        $statement->execute([
            'email' => $email,
            'password_hash' => hash_auth_password($password),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'preferred_lang' => current_language(),
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            throw new InvalidArgumentException(tr('error_email_exists'));
        }
        throw $exception;
    }

    return (int) $pdo->lastInsertId();
}

function establish_user_session(PDO $pdo, int $userId): void
{
    $statement = $pdo->prepare('SELECT id, email, first_name, last_name, role, preferred_lang FROM users WHERE id = :id AND is_active = 1');
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();
    if (!$user) {
        throw new RuntimeException('The account could not be loaded.');
    }

    session_regenerate_id(true);
    rotate_csrf_token();
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'email' => (string) $user['email'],
        'name' => trim($user['first_name'] . ' ' . $user['last_name']),
        'role' => (string) $user['role'],
    ];
    $_SESSION['preferred_lang'] = normalize_language($user['preferred_lang'] ?? 'en');
    record_activity($pdo, (int) $user['id'], 'auth.register', 'user', (int) $user['id']);
}

function sign_out(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}
