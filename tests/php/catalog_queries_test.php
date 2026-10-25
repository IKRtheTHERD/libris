<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/library.php';

$checks = 0;

function check_catalog_query(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(<<<'SQL'
CREATE TABLE categories (
    id INTEGER PRIMARY KEY,
    slug TEXT NOT NULL,
    name_en TEXT NOT NULL,
    name_ckb TEXT NOT NULL
);
CREATE TABLE books (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    subtitle TEXT,
    description TEXT,
    language_code TEXT NOT NULL,
    isbn13 TEXT,
    open_library_id TEXT,
    cover_url TEXT,
    published_year INTEGER,
    publisher TEXT,
    page_count INTEGER,
    sale_price_iqd INTEGER,
    is_active INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE book_categories (book_id INTEGER NOT NULL, category_id INTEGER NOT NULL);
CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
CREATE TABLE book_authors (book_id INTEGER NOT NULL, author_id INTEGER NOT NULL, author_order INTEGER NOT NULL);
CREATE TABLE v_book_availability (
    book_id INTEGER PRIMARY KEY,
    sale_copies_available INTEGER NOT NULL,
    loan_copies_available INTEGER NOT NULL
);
CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT NOT NULL);
CREATE TABLE reviews (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    book_id INTEGER NOT NULL,
    rating INTEGER NOT NULL,
    title TEXT,
    body TEXT,
    is_approved INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE wishlist (
    user_id INTEGER NOT NULL,
    book_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, book_id)
);
CREATE TABLE activity_log (
    actor_user_id INTEGER,
    action TEXT NOT NULL,
    entity_type TEXT,
    entity_id INTEGER,
    details TEXT,
    ip_address BLOB
);
INSERT INTO categories VALUES
    (1, 'fiction', 'Fiction', 'چیرۆک'),
    (2, 'history', 'History', 'مێژوو');
INSERT INTO books VALUES
    (1, 'The Orchard', 'A quiet novel', 'A garden story.', 'en', '9780000000001', 'OL123M', NULL, 2024, 'Ink House', 240, 12000, 1, '2026-10-24'),
    (2, 'Retired title', NULL, NULL, 'en', NULL, 'OL456W', NULL, NULL, NULL, NULL, NULL, 0, '2025-01-01'),
    (3, 'Second Orchard', NULL, NULL, 'en', NULL, 'OL789M', NULL, 2023, 'Ink House', 180, 9000, 1, '2026-10-23'),
    (4, 'Quiet History', NULL, NULL, 'en', NULL, 'OL987M', NULL, 2022, 'Ink House', 200, 7000, 1, '2026-10-22');
INSERT INTO book_categories VALUES (1, 1), (2, 1), (2, 2);
INSERT INTO authors VALUES (1, 'A. Reader'), (2, 'B. Writer'), (3, 'Retired Author');
INSERT INTO book_authors VALUES (1, 1, 1), (2, 3, 1), (3, 1, 1), (4, 2, 1);
INSERT INTO v_book_availability VALUES (1, 2, 3);
INSERT INTO users VALUES (1, 'Narin'), (2, 'Rojin'), (3, 'Dilan');
INSERT INTO reviews VALUES
    (1, 1, 1, 4, 'Thoughtful', 'A good read.', 1, '2026-10-24 10:00:00'),
    (2, 2, 1, 5, 'Beautiful', 'Warm and precise.', 1, '2026-10-25 10:00:00'),
    (3, 3, 1, 1, 'Hidden', 'Awaiting moderation.', 0, '2026-10-26 10:00:00');
SQL);

$categories = catalog_categories($pdo);
check_catalog_query((int) $categories[0]['book_count'] === 1, 'Category counts must include active catalogue titles only.');
check_catalog_query((int) $categories[1]['book_count'] === 0, 'Categories containing only inactive titles must show zero active books.');

$book = catalog_book($pdo, 1);
check_catalog_query(is_array($book) && $book['title'] === 'The Orchard', 'The detail query must return active book metadata.');
check_catalog_query($book['authors'] === ['A. Reader'], 'Book details must include authors in display order.');
check_catalog_query($book['categories'][0]['name_ckb'] === 'چیرۆک', 'Book details must carry both English and Sorani category names.');
check_catalog_query((int) $book['sale_copies_available'] === 2 && (int) $book['loan_copies_available'] === 3, 'Book details must expose current sale and loan availability.');
check_catalog_query((int) $book['review_count'] === 2 && (float) $book['average_rating'] === 4.5, 'Detail ratings must aggregate approved reviews only.');
check_catalog_query(catalog_book($pdo, 2) === null, 'Inactive titles must not be publicly addressable as book details.');

$reviews = book_reviews($pdo, 1, 1);
check_catalog_query(count($reviews) === 1 && (int) $reviews[0]['rating'] === 5, 'Review lists must return the newest approved review within the requested limit.');
check_catalog_query($reviews[0]['first_name'] === 'Rojin', 'Review cards must receive the reviewer display name.');

check_catalog_query(catalog_open_library_path('OL123M') === 'books', 'Open Library edition IDs must link to the books route.');
check_catalog_query(catalog_open_library_path('OL456W') === 'works', 'Open Library work IDs must link to the works route.');
check_catalog_query(catalog_open_library_path('OL12X') === null, 'Malformed Open Library IDs must not produce an external link.');
check_catalog_query(catalog_open_library_path('OL12M' . "\n") === null, 'Open Library IDs must match the complete ID, including the final character.');

$activeAuthors = catalog_authors($pdo);
check_catalog_query(array_column($activeAuthors, 'id') === [1, 2], 'Author options must include only authors attached to active titles in stable ID/name order.');
$authorOnePageOne = search_catalog($pdo, ['author_id' => 1, 'sort' => 'title'], 1, 1);
$authorOnePageTwo = search_catalog($pdo, ['author_id' => 1, 'sort' => 'title'], 2, 1);
check_catalog_query($authorOnePageOne['total'] === 2 && $authorOnePageOne['pages'] === 2, 'A valid author ID must include all active titles for that author and paginate the filtered total.');
check_catalog_query((int) $authorOnePageOne['books'][0]['id'] === 3 && (int) $authorOnePageTwo['books'][0]['id'] === 1, 'Author filtering must retain the requested stable title sort across pages.');
$authorTwo = search_catalog($pdo, ['author_id' => 2], 1, 12);
check_catalog_query($authorTwo['total'] === 1 && (int) $authorTwo['books'][0]['id'] === 4, 'Author filtering must exclude active titles belonging only to another author.');
$malformedAuthor = search_catalog($pdo, ['author_id' => '1 OR 1=1'], 1, 12);
check_catalog_query($malformedAuthor['total'] === 3, 'Malformed author IDs must be ignored safely without widening a parameterized author predicate.');

check_catalog_query(toggle_wishlist($pdo, 10, 1), 'The first wishlist action must add the title.');
check_catalog_query((int) $pdo->query('SELECT COUNT(*) FROM wishlist')->fetchColumn() === 1, 'Adding a wishlist entry must persist one row.');
check_catalog_query(!toggle_wishlist($pdo, 10, 1), 'The second wishlist action must remove the existing title.');
check_catalog_query((int) $pdo->query('SELECT COUNT(*) FROM wishlist')->fetchColumn() === 0, 'Removing a wishlist entry must leave no row.');
check_catalog_query((int) $pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() === 2, 'Wishlist add and remove actions must each be recorded.');

$catalogSource = file_get_contents(__DIR__ . '/../../includes/catalog.php');
$browseSource = file_get_contents(__DIR__ . '/../../books/index.php');
$detailSource = file_get_contents(__DIR__ . '/../../books/show.php');
$reviewRoute = file_get_contents(__DIR__ . '/../../reviews/create.php');
$wishlistRoute = file_get_contents(__DIR__ . '/../../wishlist/toggle.php');
check_catalog_query(is_string($catalogSource) && str_contains($catalogSource, 'b.title LIKE :title_query OR b.subtitle LIKE :subtitle_query OR a.name LIKE :author_query'), 'Catalog search must cover titles, subtitles, and authors.');
check_catalog_query(is_string($catalogSource) && str_contains($catalogSource, 'fc.slug = :category_slug') && str_contains($catalogSource, 'b.sale_price_iqd >= :min_price') && str_contains($catalogSource, 'b.sale_price_iqd <= :max_price'), 'Catalog filters must cover category and inclusive price boundaries.');
check_catalog_query(is_string($catalogSource) && str_contains($catalogSource, 'COALESCE(v.sale_copies_available, 0) > 0') && str_contains($catalogSource, 'COALESCE(v.loan_copies_available, 0) > 0'), 'Catalog availability filtering must distinguish sale and loan copies.');
check_catalog_query(is_string($catalogSource) && str_contains($catalogSource, 'LIMIT :limit OFFSET :offset') && str_contains($catalogSource, 'b.id ASC'), 'Search results must paginate and use stable tie-breakers.');
check_catalog_query(is_string($catalogSource) && str_contains($catalogSource, "'price-low' =>") && str_contains($catalogSource, "'price-high' =>") && str_contains($catalogSource, "'rating' =>"), 'Catalog must retain price and rating sort modes.');
check_catalog_query(is_string($browseSource) && str_contains($browseSource, "current_language()") && str_contains($browseSource, "['name_ckb']") && str_contains($browseSource, "['name_en']"), 'Catalog category labels must follow the active English or Sorani locale.');
check_catalog_query(is_string($browseSource) && str_contains($browseSource, "'author_id' =>") && str_contains($browseSource, 'FILTER_VALIDATE_INT') && str_contains($browseSource, 'name="author_id"'), 'The browse page must validate and render a structured author ID selector.');
check_catalog_query(is_string($browseSource) && str_contains($browseSource, 'escape_html($author[\'name\'])') && str_contains($browseSource, 'foreach ($filters as $key => $value)'), 'Author labels must be escaped and active filters must carry into the sort form.');
check_catalog_query(is_string($browseSource) && str_contains($browseSource, '$queryParams = array_filter($filters') && str_contains($browseSource, 'http_build_query($queryParams + [\'page\' => $result[\'page\'] + 1])'), 'Pagination links must retain the author ID filter.');
check_catalog_query(is_string($catalogSource) && str_contains($catalogSource, 'author_filter.author_id = :author_id') && str_contains($catalogSource, 'FILTER_VALIDATE_INT'), 'Catalog author filtering must use a validated, parameterized author relation.');
check_catalog_query(is_string($browseSource) && str_contains($browseSource, 'http_build_query($queryParams + [\'page\' => $result[\'page\'] + 1])'), 'Pagination links must retain active search and filter values.');
check_catalog_query(is_string($detailSource) && str_contains($detailSource, 'rawurlencode($openLibraryId)') && str_contains($detailSource, '$openLibraryPath !== null'), 'The detail page must only render a validated Open Library link.');
check_catalog_query(is_string($reviewRoute) && str_contains($reviewRoute, 'require_post();') && str_contains($reviewRoute, 'require_authentication();') && str_contains($reviewRoute, 'require_valid_csrf();'), 'Review submission must require POST, an authenticated user, and a valid CSRF token.');
check_catalog_query(is_string($wishlistRoute) && str_contains($wishlistRoute, 'require_post();') && str_contains($wishlistRoute, 'require_authentication();') && str_contains($wishlistRoute, 'require_valid_csrf();'), 'Wishlist mutations must require POST, an authenticated user, and a valid CSRF token.');

fwrite(STDOUT, "{$checks} catalog query and community checks passed.\n");
