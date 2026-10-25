<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_staff();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/library.php';
$pdo = db();
$definitions = [
    'loan_days' => ['default' => 14, 'min' => 1, 'max' => 90],
    'max_active_loans' => ['default' => 3, 'min' => 1, 'max' => 10],
    'late_fine_per_day_iqd' => ['default' => 1000, 'min' => 0, 'max' => 100000000],
];
$values = [];
foreach ($definitions as $key => $bounds) {
    $values[$key] = setting_int($pdo, $key, $bounds['default'], $bounds['min'], $bounds['max']);
}
$error = null;
$saved = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();
    $posted = [];
    foreach ($definitions as $key => $bounds) {
        $raw = $_POST[$key] ?? null;
        $candidate = is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT) : false;
        if ($candidate === false || $candidate < $bounds['min'] || $candidate > $bounds['max']) {
            $error = tr('settings_invalid');
            break;
        }
        $posted[$key] = $candidate;
    }
    if ($error === null) {
        try {
            $pdo->beginTransaction();
            $upsert = $pdo->prepare(
                'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            foreach ($posted as $key => $value) {
                $upsert->execute(['key' => $key, 'value' => (string) $value]);
            }
            record_activity($pdo, (int) current_user()['id'], 'settings.update', 'settings', null, $posted);
            $pdo->commit();
            $values = $posted;
            $saved = true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $error = tr('error_generic');
        }
    } else {
        foreach ($definitions as $key => $_bounds) {
            $raw = $_POST[$key] ?? null;
            $candidate = is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT) : false;
            if ($candidate !== false && $candidate >= $_bounds['min'] && $candidate <= $_bounds['max']) {
                $values[$key] = $candidate;
            }
        }
    }
}
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('manage_settings'), 'staff');
?>
<section class="page-wrap admin-shell">
    <p class="eyebrow"><?= escape_html(tr('staff')) ?></p><h1><?= escape_html(tr('manage_settings')) ?></h1>
    <?php if ($saved): ?><p class="flash flash-success" role="status"><?= escape_html(tr('changes_saved')) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="form-error" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
    <section class="account-card">
        <form method="post" action="<?= escape_html(app_url('admin/settings.php')) ?>">
            <?= csrf_field() ?>
            <div class="form-field"><label for="loan-days"><?= escape_html(tr('loan_days_setting')) ?></label><input id="loan-days" name="loan_days" type="number" min="1" max="90" required value="<?= (int) $values['loan_days'] ?>"><small class="form-help"><?= escape_html(tr('range_1_90')) ?></small></div>
            <div class="form-field"><label for="max-loans"><?= escape_html(tr('max_loans_setting')) ?></label><input id="max-loans" name="max_active_loans" type="number" min="1" max="10" required value="<?= (int) $values['max_active_loans'] ?>"><small class="form-help"><?= escape_html(tr('range_1_10')) ?></small></div>
            <div class="form-field"><label for="late-fine"><?= escape_html(tr('daily_fine_setting')) ?></label><input id="late-fine" name="late_fine_per_day_iqd" type="number" min="0" max="100000000" required value="<?= (int) $values['late_fine_per_day_iqd'] ?>"><small class="form-help"><?= escape_html(tr('iqd_per_day')) ?></small></div>
            <div class="form-field"><strong><?= escape_html(tr('catalog_limit')) ?></strong><p><?= escape_html(tr('fixed_title_limit')) ?>: 200</p></div>
            <button class="button button-ink" type="submit"><?= escape_html(tr('save_changes')) ?></button>
        </form>
    </section>
</section>
<?php render_footer(); ?>
