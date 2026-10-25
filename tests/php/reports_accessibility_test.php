<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';

$checks = 0;

function check_reports_accessibility(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

check_reports_accessibility(tr('monthly_sales_trend', 'en') !== 'monthly_sales_trend', 'The English trend label must exist.');
check_reports_accessibility(tr('monthly_sales_trend', 'ckb') !== 'monthly_sales_trend', 'The Sorani trend label must exist.');
check_reports_accessibility(tr('reporting_period', 'ckb') !== 'reporting_period', 'The Sorani reporting-period label must exist.');

$reportPage = file_get_contents(__DIR__ . '/../../admin/reports.php');
check_reports_accessibility(is_string($reportPage), 'The report page must be readable.');
check_reports_accessibility(
    str_contains($reportPage, 'aria-labelledby="monthly-sales-heading"')
        && str_contains($reportPage, 'id="monthly-sales-heading"'),
    'The sales trend section must have a programmatic name.'
);
check_reports_accessibility(
    str_contains($reportPage, 'date(\'Y-m\', strtotime($month[\'sales_month\'] . \'-01\'))')
        && str_contains($reportPage, "date('Y-m')")
        && !str_contains($reportPage, "date('M Y'")
        && !str_contains($reportPage, "date('F Y'"),
    'Report month labels must remain locale-neutral.'
);
check_reports_accessibility(
    str_contains($reportPage, 'aria-label="<?= escape_html($monthLabel .')
        && str_contains($reportPage, 'tr(\'orders\')) ?>"></progress>')
        && str_contains($reportPage, '<caption class="sr-only">')
        && str_contains($reportPage, '<th scope="col">')
        && str_contains($reportPage, '<th scope="row">'),
    'Bars must have descriptive names and a semantic tabular alternative.'
);
check_reports_accessibility(
    str_contains($reportPage, 'class="table-wrap" role="region" tabindex="0"'),
    'The chart data alternative must remain keyboard-accessible when it overflows on small screens.'
);

fwrite(STDOUT, "{$checks} report accessibility checks passed.\n");
