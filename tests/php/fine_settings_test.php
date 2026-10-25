<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/library.php';

function check_fine_settings(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)');

check_fine_settings(
    configured_late_fine($pdo, 2) === 2000,
    'Missing late fine setting must use the default 1,000 IQD/day.'
);

$save = $pdo->prepare(
    'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
     ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
);
$save->execute(['key' => 'late_fine_per_day_iqd', 'value' => '2500']);
check_fine_settings(
    configured_late_fine($pdo, 3) === 7500,
    'Late fine calculation must use the persisted daily rate.'
);

$save->execute(['key' => 'late_fine_per_day_iqd', 'value' => '-50']);
check_fine_settings(
    configured_late_fine($pdo, 1) === 1000,
    'An out-of-range persisted rate must safely fall back to the default.'
);

$save->execute(['key' => 'late_fine_per_day_iqd', 'value' => '100000001']);
check_fine_settings(
    configured_late_fine($pdo, 1) === 1000,
    'A persisted rate above the supported maximum must use the default.'
);

$save->execute(['key' => 'late_fine_per_day_iqd', 'value' => 'not-an-integer']);
check_fine_settings(
    configured_late_fine($pdo, 1) === 1000,
    'A malformed persisted rate must use the default.'
);

$save->execute(['key' => 'late_fine_per_day_iqd', 'value' => '0']);
check_fine_settings(configured_late_fine($pdo, 30) === 0, 'A valid zero rate must disable late fines.');
check_fine_settings(configured_late_fine($pdo, -2) === 0, 'Negative overdue days must not create a fine.');

$settingsSource = file_get_contents(__DIR__ . '/../../admin/settings.php');
$schemaSource = file_get_contents(__DIR__ . '/../../database/schema.sql');
check_fine_settings(is_string($settingsSource) && str_contains($settingsSource, 'require_staff();'), 'Settings must be restricted to authorized staff/admin users.');
check_fine_settings(str_contains($settingsSource, 'require_post();') && str_contains($settingsSource, 'require_valid_csrf();'), 'Settings writes must require POST and a valid CSRF token.');
check_fine_settings(str_contains($settingsSource, "'max' => 100000000") && str_contains($settingsSource, "'daily_fine_setting'"), 'The daily rate must have a bounded integer field and localized label.');
check_fine_settings(is_string($schemaSource) && str_contains($schemaSource, "('late_fine_per_day_iqd', '1000')"), 'Fresh databases must seed the 1,000 IQD/day default.');

fwrite(STDOUT, "11 fine settings checks passed.\n");
