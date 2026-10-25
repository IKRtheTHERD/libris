<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/library.php';

require_post();
require_authentication();
require_valid_csrf();

$bookId = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
if (!is_int($bookId) || $bookId < 1) {
    set_flash('error', tr('error_generic'));
    redirect_to(app_url('books/index.php'));
}

try {
    $request = request_book_loan(db(), (int) current_user()['id'], $bookId, $action);
    if ($request['status'] === 'waiting') {
        set_flash('success', tr('reserve_success'));
        redirect_to(app_url('account/reservations.php'));
    }

    set_flash('success', tr('loan_created') . ' ' . $request['due_at'] . '.');
    redirect_to(app_url('account/loans.php'));
} catch (DomainException|InvalidArgumentException $exception) {
    set_flash('error', $exception->getMessage());
} catch (PDOException) {
    set_flash('error', tr('error_generic'));
}

redirect_to(app_url('books/show.php?id=' . $bookId));
