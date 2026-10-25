<?php
declare(strict_types=1);

function check_mysql_setup_docs(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$readme = file_get_contents(__DIR__ . '/../../README.md');
if ($readme === false) {
    throw new RuntimeException('Could not read README setup instructions.');
}
$schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
if ($schema === false) {
    throw new RuntimeException('Could not read the database schema.');
}

check_mysql_setup_docs(
    str_contains($readme, 'cmd.exe /d /c "mysql -u root -p < database\\schema.sql"'),
    'Schema import must route file redirection through CMD from PowerShell.'
);
check_mysql_setup_docs(
    str_contains($readme, 'cmd.exe /d /c "mysql -u root -p -D libris < database\\seed.sql"'),
    'Seed import must route file redirection through CMD and select the libris database.'
);
check_mysql_setup_docs(
    str_contains($readme, 'The schema uses `DELIMITER` for its triggers.'),
    'Setup instructions must explain that the schema needs a MySQL-compatible CLI.'
);
check_mysql_setup_docs(
    str_contains($readme, 'MySQL 8.0.16+ or MariaDB 10.4+')
        && str_contains($schema, 'MySQL 8.0.16+ / MariaDB 10.4+'),
    'Documented database versions must enforce the CHECK constraints used by the schema.'
);

echo "MySQL setup documentation checks passed.\n";
