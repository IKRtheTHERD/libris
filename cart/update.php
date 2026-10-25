<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_post();
require_authentication();
require_valid_csrf();

$userId = (int) current_user()['id'];
$bookId = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
$quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($bookId) || $bookId < 1 || !is_int($quantity) || $quantity < 1 || $quantity > 20) {
    set_flash('error', tr('required_fields'));
    redirect_to(app_url('cart/index.php'));
}

$pdo = db();
$pdo->beginTransaction();
try {
    $book = $pdo->prepare('SELECT id FROM books WHERE id = :id AND is_active = 1 FOR UPDATE');
    $book->execute(['id' => $bookId]);
    if (!$book->fetchColumn()) {
        throw new DomainException(tr('error_title_unavailable'));
    }
    $copies = $pdo->prepare("SELECT id FROM book_copies WHERE book_id = :id AND purpose = 'sale' AND status = 'available' ORDER BY id FOR UPDATE");
    $copies->execute(['id' => $bookId]);
    if (count($copies->fetchAll(PDO::FETCH_COLUMN)) < $quantity) {
        throw new DomainException(tr('error_cart_quantity_limit'));
    }
    $update = $pdo->prepare('UPDATE cart_items SET quantity = :quantity WHERE user_id = :user_id AND book_id = :book_id');
    $update->execute(['quantity' => $quantity, 'user_id' => $userId, 'book_id' => $bookId]);
    if ($update->rowCount() === 0) {
        $exists = $pdo->prepare('SELECT 1 FROM cart_items WHERE user_id = :user_id AND book_id = :book_id');
        $exists->execute(['user_id' => $userId, 'book_id' => $bookId]);
        if (!$exists->fetchColumn()) {
            throw new DomainException(tr('error_title_not_in_bag'));
        }
    }
    $pdo->commit();
    set_flash('success', tr('changes_saved'));
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    set_flash('error', $exception->getMessage());
} catch (PDOException) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    set_flash('error', tr('error_generic'));
}

redirect_to(app_url('cart/index.php'));
