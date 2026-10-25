<?php
declare(strict_types=1);

define('LIBRIS_DB_HOST', getenv('LIBRIS_DB_HOST') ?: '127.0.0.1');
define('LIBRIS_DB_PORT', getenv('LIBRIS_DB_PORT') ?: '3306');
define('LIBRIS_DB_NAME', getenv('LIBRIS_DB_NAME') ?: 'libris');
define('LIBRIS_DB_USER', getenv('LIBRIS_DB_USER') ?: 'root');
define('LIBRIS_DB_PASSWORD', getenv('LIBRIS_DB_PASSWORD') ?: '');
define('LIBRIS_SITE_NAME', getenv('LIBRIS_SITE_NAME') ?: 'Libris');
define('LIBRIS_LOCAL_MODE', getenv('LIBRIS_LOCAL_MODE') !== '0');
