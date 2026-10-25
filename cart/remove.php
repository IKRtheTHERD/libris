<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_post();
require_authentication();
require_valid_csrf();

$bookId = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($bookId) || $bookId < 1) {
    set_flash('error', tr('error_generic'));
    redirect_to(app_url('cart/index.php'));
}

try {
    $remove = db()->prepare('DELETE FROM cart_items WHERE user_id = :user_id AND book_id = :book_id');
    $remove->execute(['user_id' => (int) current_user()['id'], 'book_id' => $bookId]);
    set_flash('success', tr('changes_saved'));
} catch (PDOException) {
    set_flash('error', tr('error_generic'));
}

redirect_to(app_url('cart/index.php'));
