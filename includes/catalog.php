<?php
declare(strict_types=1);

function catalog_categories(PDO $pdo): array
{
    return $pdo->query(
        'SELECT c.id, c.slug, c.name_en, c.name_ckb, COUNT(DISTINCT b.id) AS book_count
         FROM categories c
         LEFT JOIN book_categories bc ON bc.category_id = c.id
         LEFT JOIN books b ON b.id = bc.book_id AND b.is_active = 1
         GROUP BY c.id, c.slug, c.name_en, c.name_ckb
         ORDER BY c.name_en'
    )->fetchAll();
}

function catalog_authors(PDO $pdo): array
{
    return $pdo->query(
        'SELECT DISTINCT a.id, a.name
         FROM authors a
         JOIN book_authors ba ON ba.author_id = a.id
         JOIN books b ON b.id = ba.book_id
         WHERE b.is_active = 1
         ORDER BY a.name, a.id'
    )->fetchAll();
}

function catalog_open_library_path(string $openLibraryId): ?string
{
    if (preg_match('/^OL[0-9]+M$/D', $openLibraryId) === 1) {
        return 'books';
    }
    if (preg_match('/^OL[0-9]+W$/D', $openLibraryId) === 1) {
        return 'works';
    }

    return null;
}

function search_catalog(PDO $pdo, array $filters, int $page = 1, int $perPage = 12): array
{
    $page = max(1, $page);
    $perPage = clamp_int($perPage, 1, 48, 12);
    $where = ['b.is_active = 1'];
    $parameters = [];

    $query = trim((string) ($filters['q'] ?? ''));
    if ($query !== '') {
        $where[] = '(b.title LIKE :title_query OR b.subtitle LIKE :subtitle_query OR a.name LIKE :author_query)';
        $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        $parameters['title_query'] = $needle;
        $parameters['subtitle_query'] = $needle;
        $parameters['author_query'] = $needle;
    }

    $rawAuthorId = $filters['author_id'] ?? null;
    $authorId = is_int($rawAuthorId) || is_string($rawAuthorId)
        ? filter_var($rawAuthorId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ])
        : false;
    if ($authorId !== false) {
        $where[] = 'EXISTS (SELECT 1 FROM book_authors author_filter WHERE author_filter.book_id = b.id AND author_filter.author_id = :author_id)';
        $parameters['author_id'] = $authorId;
    }

    if (isset($filters['category']) && $filters['category'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM book_categories fbc JOIN categories fc ON fc.id = fbc.category_id WHERE fbc.book_id = b.id AND fc.slug = :category_slug)';
        $parameters['category_slug'] = (string) $filters['category'];
    }
    if (isset($filters['min_price']) && $filters['min_price'] !== '') {
        $where[] = 'b.sale_price_iqd >= :min_price';
        $parameters['min_price'] = clamp_int($filters['min_price'], 0, 100000000, 0);
    }
    if (isset($filters['max_price']) && $filters['max_price'] !== '') {
        $where[] = 'b.sale_price_iqd <= :max_price';
        $parameters['max_price'] = clamp_int($filters['max_price'], 0, 100000000, 100000000);
    }
    if (($filters['availability'] ?? '') === 'sale') {
        $where[] = 'COALESCE(v.sale_copies_available, 0) > 0';
    } elseif (($filters['availability'] ?? '') === 'loan') {
        $where[] = 'COALESCE(v.loan_copies_available, 0) > 0';
    }

    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare(
        'SELECT COUNT(DISTINCT b.id)
         FROM books b
         LEFT JOIN book_authors ba ON ba.book_id = b.id
         LEFT JOIN authors a ON a.id = ba.author_id
         LEFT JOIN v_book_availability v ON v.book_id = b.id
         WHERE ' . $whereSql
    );
    $count->execute($parameters);
    $total = (int) $count->fetchColumn();

    $sort = (string) ($filters['sort'] ?? 'recent');
    $orderBy = match ($sort) {
        'title' => 'b.title ASC, b.id ASC',
        'price-low' => 'b.sale_price_iqd IS NULL, b.sale_price_iqd ASC, b.title ASC, b.id ASC',
        'price-high' => 'b.sale_price_iqd IS NULL, b.sale_price_iqd DESC, b.title ASC, b.id ASC',
        'rating' => 'average_rating DESC, b.title ASC, b.id ASC',
        default => 'b.created_at DESC, b.id DESC',
    };
    $offset = ($page - 1) * $perPage;
    $authorAggregation = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'GROUP_CONCAT(DISTINCT a.name) AS authors'
        : "GROUP_CONCAT(DISTINCT a.name ORDER BY ba.author_order SEPARATOR ' · ') AS authors";
    $statement = $pdo->prepare(
        'SELECT b.id, b.title, b.subtitle, b.description, b.cover_url, b.open_library_id, b.isbn13,
                b.published_year, b.publisher, b.sale_price_iqd,
                ' . $authorAggregation . ',
                COALESCE(v.sale_copies_available, 0) AS sale_copies_available,
                COALESCE(v.loan_copies_available, 0) AS loan_copies_available,
                COALESCE(AVG(r.rating), 0) AS average_rating,
                COUNT(DISTINCT r.id) AS review_count
         FROM books b
         LEFT JOIN book_authors ba ON ba.book_id = b.id
         LEFT JOIN authors a ON a.id = ba.author_id
         LEFT JOIN v_book_availability v ON v.book_id = b.id
         LEFT JOIN reviews r ON r.book_id = b.id AND r.is_approved = 1
         WHERE ' . $whereSql . '
         GROUP BY b.id, v.sale_copies_available, v.loan_copies_available
         ORDER BY ' . $orderBy . '
         LIMIT :limit OFFSET :offset'
    );
    foreach ($parameters as $key => $value) {
        $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    return [
        'books' => $statement->fetchAll(),
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => max(1, (int) ceil($total / $perPage)),
    ];
}

function catalog_book(PDO $pdo, int $bookId): ?array
{
    $statement = $pdo->prepare(
        'SELECT b.id, b.title, b.subtitle, b.description, b.language_code, b.isbn13, b.open_library_id,
                b.cover_url, b.published_year, b.publisher, b.page_count, b.sale_price_iqd,
                COALESCE(v.sale_copies_available, 0) AS sale_copies_available,
                COALESCE(v.loan_copies_available, 0) AS loan_copies_available,
                COALESCE(AVG(r.rating), 0) AS average_rating,
                COUNT(DISTINCT r.id) AS review_count
         FROM books b
         LEFT JOIN v_book_availability v ON v.book_id = b.id
         LEFT JOIN reviews r ON r.book_id = b.id AND r.is_approved = 1
         WHERE b.id = :id AND b.is_active = 1
         GROUP BY b.id, v.sale_copies_available, v.loan_copies_available'
    );
    $statement->execute(['id' => $bookId]);
    $book = $statement->fetch();
    if (!$book) {
        return null;
    }

    $authors = $pdo->prepare(
        'SELECT a.name FROM authors a JOIN book_authors ba ON ba.author_id = a.id
         WHERE ba.book_id = :id ORDER BY ba.author_order, a.name'
    );
    $authors->execute(['id' => $bookId]);
    $book['authors'] = array_column($authors->fetchAll(), 'name');

    $categories = $pdo->prepare(
        'SELECT c.slug, c.name_en, c.name_ckb FROM categories c JOIN book_categories bc ON bc.category_id = c.id
         WHERE bc.book_id = :id ORDER BY c.name_en'
    );
    $categories->execute(['id' => $bookId]);
    $book['categories'] = $categories->fetchAll();

    return $book;
}

function book_reviews(PDO $pdo, int $bookId, int $limit = 8): array
{
    $limit = clamp_int($limit, 1, 30, 8);
    $statement = $pdo->prepare(
        'SELECT r.rating, r.title, r.body, r.created_at, u.first_name
         FROM reviews r JOIN users u ON u.id = r.user_id
         WHERE r.book_id = :book_id AND r.is_approved = 1
         ORDER BY r.created_at DESC LIMIT :limit'
    );
    $statement->bindValue(':book_id', $bookId, PDO::PARAM_INT);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}
