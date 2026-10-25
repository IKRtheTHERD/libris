<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';

$checkCount = 0;
$check = static function (bool $condition, string $message) use (&$checkCount): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checkCount++;
};

$adminSource = file_get_contents(__DIR__ . '/../../admin/loans.php');
$memberSource = file_get_contents(__DIR__ . '/../../account/loans.php');
$check(is_string($adminSource), 'Admin loan desk source must be readable.');
$check(is_string($memberSource), 'Member loan page source must be readable.');
$check(str_contains($adminSource, "tr('fine_assessed_at')"), 'Fine assessment date heading must use its own translation.');
foreach (['en', 'ckb'] as $language) {
    $assessmentDateLabel = LIBRIS_TRANSLATIONS[$language]['fine_assessed_at'] ?? null;
    $check(is_string($assessmentDateLabel) && $assessmentDateLabel !== '', "Fine assessment date label must be translated in {$language}.");
}

$check(str_contains($adminSource, '$loanPageSize = 50;'), 'Active loans must use a 50-row page size.');
$check(str_contains($adminSource, '$finePageSize = 50;'), 'Unpaid fines must use a separate 50-row page size.');
$check(str_contains($adminSource, "SELECT COUNT(*) FROM loans WHERE status = 'active'"), 'Active-loan pagination must count the matching loan set.');
$check(str_contains($adminSource, "SELECT COUNT(*) FROM fines WHERE status = 'unpaid'"), 'Fine pagination must count only unpaid fines.');
$check(str_contains($adminSource, '$loanOffset = ($loanPage - 1) * $loanPageSize;'), 'Loan pagination must calculate its own offset.');
$check(str_contains($adminSource, '$fineOffset = ($finePage - 1) * $finePageSize;'), 'Fine pagination must calculate its own offset.');
$check(substr_count($adminSource, 'LIMIT :limit OFFSET :offset') === 2, 'Both staff lists must bind their own LIMIT and OFFSET.');
$check(substr_count($adminSource, 'PDO::PARAM_INT') >= 4, 'Both pagination queries must bind integer limits and offsets.');
$check(str_contains($adminSource, "'loan_page' => \$loanPage - 1, 'fine_page' => \$finePage"), 'Loan navigation must preserve the current fine page.');
$check(str_contains($adminSource, "'loan_page' => \$loanPage, 'fine_page' => \$finePage - 1"), 'Fine navigation must preserve the current loan page.');
$check(str_contains($adminSource, "'loan_page' => \$requestedLoanPage, 'fine_page' => \$requestedFinePage"), 'Post/redirect/get must preserve both list pages.');
$check(substr_count($adminSource, 'name="loan_page"') === 3, 'All loan and fine mutations must submit the current loan page.');
$check(substr_count($adminSource, 'name="fine_page"') === 3, 'All loan and fine mutations must submit the current fine page.');
$check(substr_count($adminSource, '<nav class="pagination"') === 2, 'Each staff list must have its own visible pagination navigation.');

$check(str_contains($memberSource, 'f.amount_iqd AS assessed_fine, f.status AS fine_status'), 'My Loans must load both fine amount and settlement status.');
$check(str_contains($memberSource, "'unpaid' => 'fine_state_unpaid'"), 'Unpaid assessment must map to its status label.');
$check(str_contains($memberSource, "'paid' => 'fine_state_paid'"), 'Paid assessment must map to its status label.');
$check(str_contains($memberSource, "'waived' => 'fine_state_waived'"), 'Waived assessment must map to its status label.');
$check(str_contains($memberSource, "null => (int) \$loan['overdue_days'] > 0 ? 'fine_state_estimated' : null"), 'Only an overdue loan without an assessed fine must be labeled estimated.');
$check(str_contains($memberSource, 'tr($fineStateKey)'), 'My Loans must render the selected localized fine state.');

$fineStates = ['fine_state_estimated', 'fine_state_unpaid', 'fine_state_paid', 'fine_state_waived'];
foreach ($fineStates as $stateKey) {
    foreach (['en', 'ckb'] as $language) {
        $translation = LIBRIS_TRANSLATIONS[$language][$stateKey] ?? null;
        $check(is_string($translation) && $translation !== '', "{$stateKey} must be translated in {$language}.");
        $check(tr($stateKey, $language) === $translation, "{$stateKey} must resolve to its {$language} translation.");
    }
}

fwrite(STDOUT, "Loan desk pagination and fine-status checks passed ({$checkCount} checks).\n");
