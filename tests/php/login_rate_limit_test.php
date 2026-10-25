<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/login_rate_limit.php';

function check_login_rate_limit(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function login_rate_limit_test_path(string $name): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'libris-rate-' . $name . '-' . bin2hex(random_bytes(8)) . '.json';
}

$statePath = login_rate_limit_test_path('test');
$now = 1_800_000_000;
$remoteAddress = '192.0.2.10';
$_SERVER['REMOTE_ADDR'] = $remoteAddress;
$identityKey = 'identity:' . hash('sha256', 'reader@example.test' . "\0" . $remoteAddress);
$ipKey = 'ip:' . hash('sha256', $remoteAddress);

for ($attempt = 1; $attempt <= LOGIN_RATE_IDENTITY_LIMIT; $attempt++) {
    $reservation = reserve_login_rate_limit($identityKey, $ipKey, $now + $attempt, $statePath);
    check_login_rate_limit(is_string($reservation), "Identity attempt {$attempt} must be reserved.");
    finish_login_rate_limit($identityKey, $ipKey, $reservation, false, $now + $attempt, $statePath);
}
check_login_rate_limit(
    reserve_login_rate_limit($identityKey, $ipKey, $now + 10, $statePath) === null,
    'A sixth attempt for the same email/IP pair must be blocked.'
);

$differentIdentity = 'identity:' . hash('sha256', 'other@example.test' . "\0" . $remoteAddress);
$differentReservation = reserve_login_rate_limit($differentIdentity, $ipKey, $now + 11, $statePath);
check_login_rate_limit(is_string($differentReservation), 'The IP bucket must allow requests below its separate cap.');
finish_login_rate_limit($differentIdentity, $ipKey, $differentReservation, true, $now + 11, $statePath);

$expiredReservation = reserve_login_rate_limit($identityKey, $ipKey, $now + LOGIN_RATE_WINDOW_SECONDS + 10, $statePath);
check_login_rate_limit(is_string($expiredReservation), 'Entries older than 15 minutes must expire.');
finish_login_rate_limit($identityKey, $ipKey, $expiredReservation, true, $now + LOGIN_RATE_WINDOW_SECONDS + 10, $statePath);

$ipCapStatePath = login_rate_limit_test_path('ip-test');
for ($attempt = 1; $attempt <= LOGIN_RATE_IP_LIMIT; $attempt++) {
    $otherIdentity = 'identity:' . hash('sha256', "reader{$attempt}@example.test\0{$remoteAddress}");
    $reservation = reserve_login_rate_limit($otherIdentity, $ipKey, $now + $attempt, $ipCapStatePath);
    check_login_rate_limit(is_string($reservation), "IP attempt {$attempt} must be reserved.");
    finish_login_rate_limit($otherIdentity, $ipKey, $reservation, false, $now + $attempt, $ipCapStatePath);
}
check_login_rate_limit(
    reserve_login_rate_limit('identity:new', $ipKey, $now + 31, $ipCapStatePath) === null,
    'A thirty-first attempt from the same IP must be blocked across identities.'
);

$integrationPath = login_rate_limit_test_path('integration');
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
$pdo->prepare(
    'INSERT INTO users (email, password_hash, first_name, last_name, preferred_lang)
     VALUES (:email, :password_hash, :first_name, :last_name, :preferred_lang)'
)->execute([
    'email' => 'reader@example.test',
    'password_hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
    'first_name' => 'Reader',
    'last_name' => 'One',
    'preferred_lang' => 'en',
]);
$now = time();
check_login_rate_limit(
    sign_in_with_rate_limit($pdo, 'reader@example.test', 'correct horse battery staple', $now, $integrationPath),
    'Valid credentials must sign in successfully.'
);
check_login_rate_limit(
    json_decode((string) file_get_contents($integrationPath), true, 32, JSON_THROW_ON_ERROR) === [],
    'A successful sign-in must release its reservation.'
);
for ($attempt = 1; $attempt <= LOGIN_RATE_IDENTITY_LIMIT; $attempt++) {
    check_login_rate_limit(
        !sign_in_with_rate_limit($pdo, 'reader@example.test', 'wrong password', $now + $attempt, $integrationPath),
        'Incorrect credentials and throttled requests must share the same false result.'
    );
}
check_login_rate_limit(
    !sign_in_with_rate_limit($pdo, 'reader@example.test', 'wrong password', $now + 10, $integrationPath),
    'The sixth incorrect request must be rejected.'
);
check_login_rate_limit(
    !str_contains((string) file_get_contents($integrationPath), 'reader@example.test'),
    'Persistent state must not contain the submitted email.'
);

fwrite(STDOUT, "Login rate-limit checks passed.\n");

foreach ([$statePath, $ipCapStatePath, $integrationPath] as $testPath) {
    foreach ([$testPath, $testPath . '.lock'] as $temporaryFile) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
}
