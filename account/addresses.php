<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_authentication();
require_once __DIR__ . '/../includes/db.php';
$pdo = db();
$userId = (int) current_user()['id'];
$error = null;
$saved = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    require_valid_csrf();
    try {
        if (($_POST['action'] ?? '') === 'default') {
            $addressId = filter_var($_POST['address_id'] ?? null, FILTER_VALIDATE_INT);
            if ($addressId === false || $addressId < 1) {
                throw new InvalidArgumentException(tr('error_invalid_address'));
            }
            $pdo->beginTransaction();
            $ownsAddress = $pdo->prepare('SELECT id FROM addresses WHERE id = :id AND user_id = :user_id FOR UPDATE');
            $ownsAddress->execute(['id' => $addressId, 'user_id' => $userId]);
            if (!$ownsAddress->fetchColumn()) {
                throw new InvalidArgumentException(tr('error_address_not_owned'));
            }
            $clear = $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = :user_id');
            $clear->execute(['user_id' => $userId]);
            $set = $pdo->prepare('UPDATE addresses SET is_default = 1 WHERE id = :id AND user_id = :user_id');
            $set->execute(['id' => $addressId, 'user_id' => $userId]);
            record_activity($pdo, $userId, 'account.address_default', 'address', (int) $addressId);
            $pdo->commit();
            $saved = true;
        } elseif (($_POST['action'] ?? '') === 'add') {
            $label = trim(is_string($_POST['label'] ?? null) ? $_POST['label'] : 'Home');
            $recipient = trim(is_string($_POST['recipient_name'] ?? null) ? $_POST['recipient_name'] : '');
            $line1 = trim(is_string($_POST['line1'] ?? null) ? $_POST['line1'] : '');
            $line2 = trim(is_string($_POST['line2'] ?? null) ? $_POST['line2'] : '');
            $city = trim(is_string($_POST['city'] ?? null) ? $_POST['city'] : '');
            $region = trim(is_string($_POST['region'] ?? null) ? $_POST['region'] : '');
            $postalCode = trim(is_string($_POST['postal_code'] ?? null) ? $_POST['postal_code'] : '');
            $country = strtoupper(trim(is_string($_POST['country_code'] ?? null) ? $_POST['country_code'] : 'IQ'));
            $makeDefault = ($_POST['is_default'] ?? null) === '1';
            if ($recipient === '' || $line1 === '' || $city === '' || mb_strlen($recipient) > 200 || mb_strlen($line1) > 200 || mb_strlen($line2) > 200 || mb_strlen($city) > 100 || mb_strlen($region) > 100 || mb_strlen($postalCode) > 32 || mb_strlen($label) > 80 || preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw new InvalidArgumentException(tr('error_address_fields'));
            }
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO addresses (user_id, label, recipient_name, line1, line2, city, region, postal_code, country_code, is_default)
                 VALUES (:user_id, :label, :recipient_name, :line1, :line2, :city, :region, :postal_code, :country_code, :is_default)'
            );
            $insert->execute([
                'user_id' => $userId, 'label' => $label !== '' ? $label : 'Home', 'recipient_name' => $recipient,
                'line1' => $line1, 'line2' => $line2 !== '' ? $line2 : null, 'city' => $city,
                'region' => $region !== '' ? $region : null, 'postal_code' => $postalCode !== '' ? $postalCode : null,
                'country_code' => $country, 'is_default' => $makeDefault ? 1 : 0,
            ]);
            $newId = (int) $pdo->lastInsertId();
            if ($makeDefault) {
                $clear = $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = :user_id AND id <> :id');
                $clear->execute(['user_id' => $userId, 'id' => $newId]);
            }
            record_activity($pdo, $userId, 'account.address_add', 'address', $newId);
            $pdo->commit();
            $saved = true;
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        $error = tr('error_generic');
    }
}

$statement = $pdo->prepare('SELECT id, label, recipient_name, line1, line2, city, region, postal_code, country_code, is_default FROM addresses WHERE user_id = :user_id ORDER BY is_default DESC, id DESC');
$statement->execute(['user_id' => $userId]);
$addresses = $statement->fetchAll();
require_once __DIR__ . '/../includes/layout.php';
render_header(tr('profile'), 'account');
?>
<section class="page-wrap account-layout">
    <nav class="account-nav" aria-label="<?= escape_html(tr('account')) ?>">
        <a href="#addresses"><?= escape_html(tr('saved_addresses')) ?></a>
        <a href="#new-address"><?= escape_html(tr('add_address')) ?></a>
    </nav>
    <div class="account-content">
    <p class="eyebrow"><?= escape_html(tr('account_eyebrow')) ?></p>
    <h1><?= escape_html(tr('address')) ?></h1>
    <?php if ($saved): ?><p class="form-success" role="status"><?= escape_html(tr('changes_saved')) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="form-error" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
    <div id="addresses">
        <section class="account-card" aria-labelledby="saved-addresses-title">
            <h2 id="saved-addresses-title"><?= escape_html(tr('saved_addresses')) ?></h2>
            <?php if ($addresses === []): ?><p><?= escape_html(tr('empty_addresses')) ?></p><?php endif; ?>
            <?php foreach ($addresses as $address): ?>
                <article class="address-entry">
                    <h3><?= escape_html($address['label']) ?><?php if ((bool) $address['is_default']): ?> <span class="status-pill"><?= escape_html(tr('default_address')) ?></span><?php endif; ?></h3>
                    <p><?= escape_html($address['recipient_name']) ?><br><?= escape_html($address['line1']) ?><?= $address['line2'] ? ', ' . escape_html($address['line2']) : '' ?><br><?= escape_html($address['city']) ?><?= $address['region'] ? ', ' . escape_html($address['region']) : '' ?> <?= escape_html($address['postal_code'] ?? '') ?><br><?= escape_html($address['country_code']) ?></p>
                    <?php if (!(bool) $address['is_default']): ?>
                        <form method="post" action="<?= escape_html(app_url('account/addresses.php')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="action" value="default"><input type="hidden" name="address_id" value="<?= (int) $address['id'] ?>">
                            <button class="button button-small" type="submit"><?= escape_html(tr('set_default')) ?></button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
        <section class="account-card" id="new-address" aria-labelledby="add-address-title">
            <h2 id="add-address-title"><?= escape_html(tr('add_address')) ?></h2>
            <form method="post" action="<?= escape_html(app_url('account/addresses.php')) ?>" class="stacked-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="add">
                <label for="label"><?= escape_html(tr('address_label')) ?></label><input id="label" name="label" maxlength="80" value="<?= escape_html(tr('home')) ?>">
                <label for="recipient-name"><?= escape_html(tr('recipient')) ?></label><input id="recipient-name" name="recipient_name" maxlength="200" autocomplete="name" required>
                <label for="line1"><?= escape_html(tr('address')) ?></label><input id="line1" name="line1" maxlength="200" autocomplete="address-line1" required>
                <label for="line2"><?= escape_html(tr('address_line2')) ?></label><input id="line2" name="line2" maxlength="200" autocomplete="address-line2">
                <label for="city"><?= escape_html(tr('city')) ?></label><input id="city" name="city" maxlength="100" autocomplete="address-level2" required>
                <label for="region"><?= escape_html(tr('region')) ?></label><input id="region" name="region" maxlength="100" autocomplete="address-level1">
                <label for="postal-code"><?= escape_html(tr('postal_code')) ?></label><input id="postal-code" name="postal_code" maxlength="32" autocomplete="postal-code">
                <label for="country-code"><?= escape_html(tr('country')) ?></label><input id="country-code" name="country_code" minlength="2" maxlength="2" pattern="[A-Za-z]{2}" value="IQ" autocomplete="country" required>
                <label class="form-field"><input type="checkbox" name="is_default" value="1"> <?= escape_html(tr('set_default')) ?></label>
                <button class="button button-ink" type="submit"><?= escape_html(tr('save_changes')) ?></button>
            </form>
        </section>
    </div>
    </div>
</section>
<?php render_footer(); ?>
