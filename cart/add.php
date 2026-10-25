<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/library.php';

require_post();
require_authentication();
require_valid_csrf();

$bookId = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($bookId) || $bookId < 1) {
    set_flash('error', tr('error_generic'));
    redirect_to(app_url('books/index.php'));
}

try {
    add_book_to_cart(db(), (int) current_user()['id'], $bookId);
    set_flash('success', tr('added_to_cart'));
} catch (DomainException $exception) {
    set_flash('error', $exception->getMessage());
} catch (PDOException) {
    set_flash('error', tr('error_generic'));
}

redirect_to(app_url('cart/index.php'));
