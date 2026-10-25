<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_post();
require_authentication();
require_valid_csrf();

$bookId = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
$rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
$title = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
$body = isset($_POST['body']) && is_string($_POST['body']) ? trim($_POST['body']) : '';
if (!is_int($bookId) || $bookId < 1 || !is_int($rating) || $rating < 1 || $rating > 5 || mb_strlen($title) > 160 || mb_strlen($body) > 3000) {
    set_flash('error', tr('required_fields'));
    redirect_to(app_url('books/index.php'));
}

$pdo = db();
$pdo->beginTransaction();
try {
    $book = $pdo->prepare('SELECT id FROM books WHERE id = :book_id AND is_active = 1 FOR UPDATE');
    $book->execute(['book_id' => $bookId]);
    if (!$book->fetchColumn()) {
        throw new DomainException(tr('error_title_unavailable'));
    }
    $statement = $pdo->prepare(
        'INSERT INTO reviews (user_id, book_id, rating, title, body, is_approved)
         VALUES (:user_id, :book_id, :rating, :title, :body, 1)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), title = VALUES(title), body = VALUES(body), is_approved = 1'
    );
    $statement->execute([
        'user_id' => (int) current_user()['id'],
        'rating' => $rating,
        'title' => $title === '' ? null : $title,
        'body' => $body === '' ? null : $body,
        'book_id' => $bookId,
    ]);
    record_activity($pdo, (int) current_user()['id'], 'review.save', 'book', $bookId, ['rating' => $rating]);
    $pdo->commit();
    set_flash('success', tr('review_saved'));
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', $exception->getMessage());
} catch (PDOException) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', tr('error_generic'));
}

redirect_to(app_url('books/show.php?id=' . $bookId));
