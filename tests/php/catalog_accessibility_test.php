<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';

$checks = 0;

function check_catalog_accessibility(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

check_catalog_accessibility(tr('pagination', 'en') === 'Pagination', 'The English pagination landmark must be labeled.');
check_catalog_accessibility(tr('pagination', 'ckb') === 'لاپەڕەگۆڕکێ', 'The Sorani pagination landmark must be localized.');
check_catalog_accessibility(
    sprintf(tr('reviewed_on', 'en'), '2026-10-25') === 'Reviewed on 2026-10-25',
    'The English review date announcement must use a locale-neutral date.'
);
check_catalog_accessibility(
    sprintf(tr('reviewed_on', 'ckb'), '2026-10-25') !== 'Reviewed on 2026-10-25',
    'The Sorani review date announcement must be localized.'
);
check_catalog_accessibility(
    LIBRIS_TRANSLATIONS['en']['pagination'] !== ''
        && LIBRIS_TRANSLATIONS['ckb']['pagination'] !== ''
        && LIBRIS_TRANSLATIONS['en']['reviewed_on'] !== ''
        && LIBRIS_TRANSLATIONS['ckb']['reviewed_on'] !== '',
    'Both locales must define every new accessibility label.'
);

$catalogPage = file_get_contents(__DIR__ . '/../../books/index.php');
$bookPage = file_get_contents(__DIR__ . '/../../books/show.php');
check_catalog_accessibility(
    is_string($catalogPage) && str_contains($catalogPage, "aria-label=\"<?= escape_html(tr('pagination')) ?>\""),
    'The catalog pagination landmark must use its translated label.'
);
check_catalog_accessibility(
    is_string($bookPage)
        && str_contains($bookPage, '<time datetime="<?= escape_html($reviewDate) ?>"')
        && str_contains($bookPage, 'sprintf(tr(\'reviewed_on\'), $reviewDate)')
        && !str_contains($bookPage, "date('M j, Y'"),
    'Reader review dates must be semantic, localized for assistive technology, and free of English month names.'
);

fwrite(STDOUT, "{$checks} catalog accessibility checks passed.\n");
