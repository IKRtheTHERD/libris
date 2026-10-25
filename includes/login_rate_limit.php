<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const LOGIN_RATE_WINDOW_SECONDS = 900;
const LOGIN_RATE_IDENTITY_LIMIT = 5;
const LOGIN_RATE_IP_LIMIT = 30;
const LOGIN_RATE_MAX_BUCKETS = 4096;
const LOGIN_RATE_MAX_STATE_BYTES = 8388608;

function sign_in_with_rate_limit(
    PDO $pdo,
    string $email,
    string $password,
    ?int $now = null,
    ?string $statePath = null
): bool {
    $now ??= time();
    $statePath ??= default_login_rate_limit_path();
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $remoteAddress = is_string($remoteAddress) && filter_var($remoteAddress, FILTER_VALIDATE_IP)
        ? $remoteAddress
        : 'unknown';
    $normalizedEmail = strtolower(trim($email));
    $identityKey = 'identity:' . hash('sha256', $normalizedEmail . "\0" . $remoteAddress);
    $ipKey = 'ip:' . hash('sha256', $remoteAddress);
    $reservation = reserve_login_rate_limit($identityKey, $ipKey, $now, $statePath);
    if ($reservation === null) {
        return false;
    }

    try {
        $signedIn = sign_in($pdo, $normalizedEmail, $password);
    } catch (Throwable $exception) {
        try {
            finish_login_rate_limit($identityKey, $ipKey, $reservation, true, $now, $statePath);
        } catch (Throwable $cleanupException) {
            error_log($cleanupException->getMessage());
        }
        throw $exception;
    }

    if ($signedIn) {
        try {
            finish_login_rate_limit($identityKey, $ipKey, $reservation, true, $now, $statePath);
        } catch (Throwable $cleanupException) {
            error_log($cleanupException->getMessage());
        }
    }
    return $signedIn;
}

function reserve_login_rate_limit(string $identityKey, string $ipKey, int $now, string $statePath): ?string
{
    return with_login_rate_state($statePath, static function (array &$state) use ($identityKey, $ipKey, $now): ?string {
        prune_login_rate_state($state, $now);
        $identityCount = count($state[$identityKey] ?? []);
        $ipCount = count($state[$ipKey] ?? []);
        if ($identityCount >= LOGIN_RATE_IDENTITY_LIMIT || $ipCount >= LOGIN_RATE_IP_LIMIT) {
            return null;
        }

        $newBuckets = (isset($state[$identityKey]) ? 0 : 1) + (isset($state[$ipKey]) ? 0 : 1);
        if (count($state) + $newBuckets > LOGIN_RATE_MAX_BUCKETS) {
            return null;
        }

        $reservation = bin2hex(random_bytes(16));
        $entry = ['id' => $reservation, 'at' => $now];
        $state[$identityKey][] = $entry;
        $state[$ipKey][] = $entry;
        return $reservation;
    }, $now);
}

function finish_login_rate_limit(
    string $identityKey,
    string $ipKey,
    string $reservation,
    bool $removeReservation,
    int $now,
    string $statePath
): void {
    if (!$removeReservation) {
        return;
    }

    with_login_rate_state($statePath, static function (array &$state) use ($identityKey, $ipKey, $reservation, $now): mixed {
        prune_login_rate_state($state, $now);
        foreach ([$identityKey, $ipKey] as $key) {
            if (!isset($state[$key])) {
                continue;
            }
            $state[$key] = array_values(array_filter(
                $state[$key],
                static fn (array $entry): bool => !hash_equals($entry['id'], $reservation)
            ));
            if ($state[$key] === []) {
                unset($state[$key]);
            }
        }
        return null;
    }, $now);
}

function with_login_rate_state(string $statePath, callable $operation, int $now): mixed
{
    $directory = dirname($statePath);
    if (!is_dir($directory) || is_link($statePath) || is_link($statePath . '.lock')) {
        throw new RuntimeException('Login rate-limit storage is unavailable.');
    }

    $lock = @fopen($statePath . '.lock', 'c+');
    if ($lock === false) {
        throw new RuntimeException('Login rate-limit storage is unavailable.');
    }
    @chmod($statePath . '.lock', 0600);

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Login rate-limit storage is unavailable.');
        }

        $stateFile = @fopen($statePath, 'c+');
        if ($stateFile === false) {
            throw new RuntimeException('Login rate-limit storage is unavailable.');
        }
        @chmod($statePath, 0600);

        try {
            $state = read_login_rate_state($stateFile);
            $result = $operation($state);
            write_login_rate_state($stateFile, $state);
            return $result;
        } finally {
            fclose($stateFile);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function read_login_rate_state($stateFile): array
{
    $size = fstat($stateFile)['size'] ?? 0;
    if ($size > LOGIN_RATE_MAX_STATE_BYTES) {
        throw new RuntimeException('Login rate-limit storage exceeds its size limit.');
    }
    rewind($stateFile);
    $contents = stream_get_contents($stateFile);
    if ($contents === false || $contents === '') {
        return [];
    }

    try {
        $state = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Login rate-limit storage is unreadable.', 0, $exception);
    }
    if (!is_array($state)) {
        throw new RuntimeException('Login rate-limit storage is unreadable.');
    }

    foreach ($state as $key => $entries) {
        if (!is_string($key) || !is_array($entries)) {
            throw new RuntimeException('Login rate-limit storage is unreadable.');
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['id'], $entry['at']) || !is_string($entry['id']) || !is_int($entry['at'])) {
                throw new RuntimeException('Login rate-limit storage is unreadable.');
            }
        }
    }

    return $state;
}

function write_login_rate_state($stateFile, array $state): void
{
    $contents = json_encode($state, JSON_THROW_ON_ERROR);
    if (strlen($contents) > LOGIN_RATE_MAX_STATE_BYTES) {
        throw new RuntimeException('Login rate-limit storage exceeds its size limit.');
    }
    rewind($stateFile);
    if (!ftruncate($stateFile, 0)) {
        throw new RuntimeException('Login rate-limit storage could not be updated.');
    }

    $remaining = $contents;
    while ($remaining !== '') {
        $written = fwrite($stateFile, $remaining);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Login rate-limit storage could not be updated.');
        }
        $remaining = substr($remaining, $written);
    }
    if (!fflush($stateFile)) {
        throw new RuntimeException('Login rate-limit storage could not be updated.');
    }
}

function prune_login_rate_state(array &$state, int $now): void
{
    $cutoff = $now - LOGIN_RATE_WINDOW_SECONDS;
    foreach ($state as $key => $entries) {
        $state[$key] = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['at'] > $cutoff
        ));
        if ($state[$key] === []) {
            unset($state[$key]);
        }
    }
}

function default_login_rate_limit_path(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'libris-login-rate-limit-' . substr(hash('sha256', __DIR__), 0, 16) . '.json';
}
