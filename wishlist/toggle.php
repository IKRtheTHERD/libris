<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/library.php';

require_post();
require_authentication();
require_valid_csrf();

$bookId = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
$returnToWishlist = ($_POST['return_to'] ?? '') === 'wishlist';
$returnUrl = $returnToWishlist ? app_url('account/wishlist.php') : null;
if (!is_int($bookId) || $bookId < 1) {
    set_flash('error', tr('error_generic'));
    redirect_to(app_url('account/wishlist.php'));
}

try {
    $saved = toggle_wishlist(db(), (int) current_user()['id'], $bookId);
    set_flash('success', tr($saved ? 'wish_saved' : 'wish_removed'));
} catch (PDOException) {
    set_flash('error', tr('error_generic'));
}

redirect_to($returnUrl ?? app_url('books/show.php?id=' . $bookId));
