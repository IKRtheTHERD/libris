<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

function check_session_security(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

check_session_security(
    ini_get('session.use_strict_mode') === '1',
    'PHP strict session mode must be enabled before the application session starts.'
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    'CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        first_name TEXT NOT NULL,
        last_name TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT \'customer\',
        preferred_lang TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1
    )'
);
$pdo->exec(
    'CREATE TABLE activity_log (
        actor_user_id INTEGER,
        action TEXT NOT NULL,
        entity_type TEXT,
        entity_id INTEGER,
        details TEXT,
        ip_address BLOB
    )'
);

$insertUser = $pdo->prepare(
    'INSERT INTO users (email, password_hash, first_name, last_name, role, preferred_lang)
     VALUES (:email, :password_hash, :first_name, :last_name, :role, :preferred_lang)'
);
$insertUser->execute([
    'email' => 'reader@example.test',
    'password_hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
    'first_name' => 'Reader',
    'last_name' => 'One',
    'role' => 'customer',
    'preferred_lang' => 'en',
]);

$preLoginToken = csrf_token();
check_session_security(sign_in($pdo, 'reader@example.test', 'correct horse battery staple'), 'A valid account must sign in.');
$postLoginToken = csrf_token();
check_session_security($postLoginToken !== $preLoginToken, 'Successful sign-in must rotate the CSRF token.');
check_session_security(!verify_csrf_token($preLoginToken), 'A pre-login CSRF token must fail after sign-in.');
check_session_security(verify_csrf_token($postLoginToken), 'The post-login CSRF token must remain valid.');

$preRegistrationToken = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $preRegistrationToken;
$registeredId = register_customer(
    $pdo,
    'new-reader@example.test',
    'New',
    'Reader',
    'another correct horse battery staple'
);
establish_user_session($pdo, $registeredId);
$postRegistrationToken = csrf_token();
check_session_security($postRegistrationToken !== $preRegistrationToken, 'Registration session establishment must rotate the CSRF token.');
check_session_security(!verify_csrf_token($preRegistrationToken), 'A pre-registration CSRF token must fail after session establishment.');
check_session_security(verify_csrf_token($postRegistrationToken), 'The post-registration CSRF token must remain valid.');

$longPassword = str_repeat('a', 100);
register_customer($pdo, 'long-password@example.test', 'Long', 'Password', $longPassword);
check_session_security(
    sign_in($pdo, 'long-password@example.test', $longPassword),
    'A registered password longer than bcrypt’s byte limit must authenticate in full.'
);
check_session_security(
    !sign_in($pdo, 'long-password@example.test', $longPassword . 'changed'),
    'Changing bytes beyond bcrypt’s byte limit must not authenticate as the registered password.'
);

fwrite(STDOUT, "Session and CSRF security checks passed.\n");
