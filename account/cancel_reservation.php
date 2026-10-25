<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/library.php';
require_post();
require_authentication();
require_valid_csrf();

$reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($reservationId) || $reservationId < 1) {
    set_flash('error', tr('error_generic'));
    redirect_to(app_url('account/reservations.php'));
}

try {
    $cancelled = cancel_waiting_reservation(db(), (int) current_user()['id'], $reservationId);
    set_flash($cancelled ? 'success' : 'error', tr($cancelled ? 'reservation_cancelled' : 'reservation_not_cancellable'));
} catch (PDOException) {
    set_flash('error', tr('error_generic'));
}

redirect_to(app_url('account/reservations.php'));
