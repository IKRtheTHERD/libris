<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function setting_int(PDO $pdo, string $key, int $default, int $minimum, int $maximum): int
{
    $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
    $statement->execute(['key' => $key]);
    $value = $statement->fetchColumn();

    return clamp_int($value === false ? $default : $value, $minimum, $maximum, $default);
}

function configured_late_fine(PDO $pdo, int $overdueDays): int
{
    $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
    $statement->execute(['key' => 'late_fine_per_day_iqd']);
    $storedRate = $statement->fetchColumn();
    $parsedRate = $storedRate === false ? false : filter_var($storedRate, FILTER_VALIDATE_INT);
    $dailyRate = $parsedRate !== false && $parsedRate >= 0 && $parsedRate <= 100000000
        ? $parsedRate
        : 1000;

    return calculate_late_fine($overdueDays, $dailyRate);
}

function cancel_waiting_reservation(PDO $pdo, int $userId, int $reservationId): bool
{
    if ($userId < 1 || $reservationId < 1) {
        return false;
    }

    $lockClause = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $bookQuery = $pdo->prepare(
            "SELECT book_id FROM reservations
             WHERE id = :id AND user_id = :user_id AND status = 'waiting'"
        );
        $bookQuery->execute(['id' => $reservationId, 'user_id' => $userId]);
        $bookId = $bookQuery->fetchColumn();
        if ($bookId === false) {
            $pdo->commit();
            return false;
        }

        // Serialize updates for one title so two members cannot receive the same place.
        $bookLock = $pdo->prepare('SELECT id FROM books WHERE id = :id' . $lockClause);
        $bookLock->execute(['id' => (int) $bookId]);
        if (!$bookLock->fetchColumn()) {
            $pdo->rollBack();
            return false;
        }

        $reservationQuery = $pdo->prepare(
            'SELECT id, book_id FROM reservations
             WHERE id = :id AND user_id = :user_id AND status = \'waiting\'' . $lockClause
        );
        $reservationQuery->execute(['id' => $reservationId, 'user_id' => $userId]);
        $reservation = $reservationQuery->fetch();
        if (!$reservation) {
            $pdo->commit();
            return false;
        }

        $cancel = $pdo->prepare(
            "UPDATE reservations SET status = 'cancelled', queue_position = NULL
             WHERE id = :id AND user_id = :user_id AND status = 'waiting'"
        );
        $cancel->execute(['id' => $reservationId, 'user_id' => $userId]);
        if ($cancel->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        $waiting = $pdo->prepare(
            "SELECT id FROM reservations WHERE book_id = :book_id AND status = 'waiting'
             ORDER BY created_at, id"
        );
        $waiting->execute(['book_id' => (int) $reservation['book_id']]);
        $updatePosition = $pdo->prepare(
            "UPDATE reservations SET queue_position = :position
             WHERE id = :id AND status = 'waiting'"
        );
        foreach ($waiting->fetchAll(PDO::FETCH_COLUMN) as $index => $waitingId) {
            $updatePosition->execute(['position' => $index + 1, 'id' => (int) $waitingId]);
        }

        record_activity($pdo, $userId, 'library.reservation_cancel', 'reservation', $reservationId);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function add_book_to_cart(PDO $pdo, int $userId, int $bookId): void
{
    $pdo->beginTransaction();
    try {
        $book = $pdo->prepare('SELECT sale_price_iqd FROM books WHERE id = :id AND is_active = 1 FOR UPDATE');
        $book->execute(['id' => $bookId]);
        $bookData = $book->fetch();
        if (!$bookData || $bookData['sale_price_iqd'] === null) {
            throw new DomainException(tr('error_title_not_for_sale'));
        }

        $availableCopies = $pdo->prepare(
            "SELECT id FROM book_copies WHERE book_id = :book_id AND purpose = 'sale' AND status = 'available' ORDER BY id FOR UPDATE"
        );
        $availableCopies->execute(['book_id' => $bookId]);
        $availableCount = count($availableCopies->fetchAll(PDO::FETCH_COLUMN));
        if ($availableCount < 1) {
            throw new DomainException(tr('error_title_not_for_sale'));
        }

        $cart = $pdo->prepare('SELECT quantity FROM cart_items WHERE user_id = :user_id AND book_id = :book_id FOR UPDATE');
        $cart->execute(['user_id' => $userId, 'book_id' => $bookId]);
        $currentQuantity = (int) ($cart->fetchColumn() ?: 0);
        if ($currentQuantity >= $availableCount || $currentQuantity >= 20) {
            throw new DomainException(tr('error_sale_quantity_limit'));
        }

        $add = $pdo->prepare(
            'INSERT INTO cart_items (user_id, book_id, quantity) VALUES (:user_id, :book_id, 1)
             ON DUPLICATE KEY UPDATE quantity = quantity + 1'
        );
        $add->execute(['user_id' => $userId, 'book_id' => $bookId]);
        record_activity($pdo, $userId, 'cart.add', 'book', $bookId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function assert_reservation_action_needs_queue(string $action, ?array $copy): void
{
    if ($action === 'reserve' && $copy !== null) {
        throw new DomainException(tr('copy_available_to_borrow'));
    }
}

function request_book_loan(PDO $pdo, int $userId, int $bookId, string $action): array
{
    if (!in_array($action, ['borrow', 'reserve'], true)) {
        throw new InvalidArgumentException(tr('error_loan_action'));
    }

    $pdo->beginTransaction();
    try {
        $member = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 FOR UPDATE');
        $member->execute(['id' => $userId]);
        if (!$member->fetchColumn()) {
            throw new DomainException(tr('error_account_inactive'));
        }

        $maxLoans = setting_int($pdo, 'max_active_loans', 3, 1, 10);
        $activeCount = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE user_id = :user_id AND status = 'active'");
        $activeCount->execute(['user_id' => $userId]);
        if ((int) $activeCount->fetchColumn() >= $maxLoans) {
            throw new DomainException(tr('error_active_loan_limit'));
        }

$book = $pdo->prepare('SELECT id FROM books WHERE id = :id AND is_active = 1 FOR UPDATE');
        $book->execute(['id' => $bookId]);
        if (!$book->fetchColumn()) {
            throw new DomainException(tr('error_title_unavailable'));
        }

        $ownReservation = $pdo->prepare(
            "SELECT id, status FROM reservations WHERE user_id = :user_id AND book_id = :book_id
             AND status IN ('waiting','ready') ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $ownReservation->execute(['user_id' => $userId, 'book_id' => $bookId]);
        $reservation = $ownReservation->fetch();
        $copy = null;

        if ($reservation && $reservation['status'] === 'ready') {
            $readyCopy = $pdo->prepare(
                "SELECT id FROM book_copies WHERE book_id = :book_id AND purpose = 'loan' AND status = 'reserved'
                 ORDER BY id LIMIT 1 FOR UPDATE"
            );
            $readyCopy->execute(['book_id' => $bookId]);
            $copy = $readyCopy->fetch();
            if (!$copy) {
                throw new DomainException(tr('error_reserved_copy_unavailable'));
            }
        } else {
            $firstWaiting = $pdo->prepare(
                "SELECT id, user_id FROM reservations WHERE book_id = :book_id AND status = 'waiting'
                 ORDER BY created_at, id LIMIT 1 FOR UPDATE"
            );
            $firstWaiting->execute(['book_id' => $bookId]);
            $first = $firstWaiting->fetch();
            if ($first && (int) $first['user_id'] !== $userId) {
                if ($action === 'borrow') {
                    throw new DomainException(tr('error_reservation_priority'));
                }
                if (!$reservation) {
                    $queuePosition = $pdo->prepare("SELECT COUNT(*) + 1 FROM reservations WHERE book_id = :book_id AND status = 'waiting'");
                    $queuePosition->execute(['book_id' => $bookId]);
                    $insert = $pdo->prepare(
                        "INSERT INTO reservations (user_id, book_id, status, queue_position) VALUES (:user_id, :book_id, 'waiting', :position)"
                    );
                    $insert->execute(['user_id' => $userId, 'book_id' => $bookId, 'position' => (int) $queuePosition->fetchColumn()]);
                    record_activity($pdo, $userId, 'library.reserve', 'book', $bookId);
                    $pdo->commit();
                    return ['status' => 'waiting'];
                }
            }

if (!$copy) {
    $availableCopy = $pdo->prepare(
        "SELECT id FROM book_copies WHERE book_id = :book_id AND purpose = 'loan' AND status = 'available'
         ORDER BY id LIMIT 1 FOR UPDATE"
    );
    $availableCopy->execute(['book_id' => $bookId]);
    $copy = $availableCopy->fetch();
}
            if (!$copy) {
                if ($action === 'borrow') {
                    throw new DomainException(tr('error_no_loan_copies'));
                }
                if (!$reservation) {
                    $queuePosition = $pdo->prepare("SELECT COUNT(*) + 1 FROM reservations WHERE book_id = :book_id AND status = 'waiting'");
                    $queuePosition->execute(['book_id' => $bookId]);
                    $insert = $pdo->prepare(
                        "INSERT INTO reservations (user_id, book_id, status, queue_position) VALUES (:user_id, :book_id, 'waiting', :position)"
                    );
                    $insert->execute(['user_id' => $userId, 'book_id' => $bookId, 'position' => (int) $queuePosition->fetchColumn()]);
                    record_activity($pdo, $userId, 'library.reserve', 'book', $bookId);
                    $pdo->commit();
                    return ['status' => 'waiting'];
                }
                throw new DomainException(tr('error_already_waiting'));
            }
        }

assert_reservation_action_needs_queue($action, $copy);
$loanDays = setting_int($pdo, 'loan_days', 14, 1, 90);
        $dueDate = $pdo->query('SELECT DATE_ADD(CURDATE(), INTERVAL ' . $loanDays . ' DAY)')->fetchColumn();
        $insertLoan = $pdo->prepare(
            "INSERT INTO loans (user_id, copy_id, borrowed_at, due_at, status)
             VALUES (:user_id, :copy_id, CURDATE(), :due_at, 'active')"
        );
        $insertLoan->execute(['user_id' => $userId, 'copy_id' => (int) $copy['id'], 'due_at' => $dueDate]);
        $updateCopy = $pdo->prepare("UPDATE book_copies SET status = 'loaned' WHERE id = :id AND status IN ('available','reserved')");
        $updateCopy->execute(['id' => (int) $copy['id']]);
        if ($updateCopy->rowCount() !== 1) {
            throw new DomainException(tr('error_copy_checked_out'));
        }
        if ($reservation) {
            $complete = $pdo->prepare("UPDATE reservations SET status = 'fulfilled' WHERE id = :id");
            $complete->execute(['id' => (int) $reservation['id']]);
        }
        $loanId = (int) $pdo->lastInsertId();
        record_activity($pdo, $userId, 'library.borrow', 'loan', $loanId, ['book_id' => $bookId]);
        $pdo->commit();

        return ['status' => 'borrowed', 'loan_id' => $loanId, 'due_at' => (string) $dueDate];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function return_book_loan(PDO $pdo, int $loanId, int $actorId): int
{
    if ($loanId < 1 || $actorId < 1) {
        throw new InvalidArgumentException('A valid loan and staff actor are required.');
    }
    $lockClause = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $bookLookup = $pdo->prepare(
            'SELECT c.book_id FROM loans l JOIN book_copies c ON c.id = l.copy_id WHERE l.id = :id'
        );
        $bookLookup->execute(['id' => $loanId]);
        $bookId = $bookLookup->fetchColumn();
        if ($bookId === false) {
            throw new DomainException(tr('error_loan_not_found'));
        }

        // Serialize return-side queue allocation with borrow, cancel, and staff queue actions.
        $bookLock = $pdo->prepare("SELECT id FROM books WHERE id = :id{$lockClause}");
        $bookLock->execute(['id' => (int) $bookId]);
        if ($bookLock->fetchColumn() === false) {
            throw new DomainException(tr('error_title_unavailable'));
        }

        $loanQuery = $pdo->prepare(
            "SELECT l.id, l.copy_id, l.user_id, l.due_at
             FROM loans l WHERE l.id = :id AND l.status = 'active'{$lockClause}"
        );
        $loanQuery->execute(['id' => $loanId]);
        $loan = $loanQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($loan)) {
            throw new DomainException(tr('error_loan_not_found'));
        }

        $today = new DateTimeImmutable((string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn());
        $returnedAt = $today->format('Y-m-d');
        $dueAt = new DateTimeImmutable((string) $loan['due_at']);
        $daysLate = $dueAt < $today ? (int) $dueAt->diff($today)->days : 0;
        $fine = configured_late_fine($pdo, $daysLate);

        $updateLoan = $pdo->prepare(
            "UPDATE loans SET status = 'returned', returned_at = :returned_at
             WHERE id = :id AND status = 'active'"
        );
        $updateLoan->execute(['returned_at' => $returnedAt, 'id' => $loanId]);
        if ($updateLoan->rowCount() !== 1) {
            throw new DomainException(tr('error_loan_not_found'));
        }
        $copy = $pdo->prepare(
            "UPDATE book_copies SET status = 'available' WHERE id = :id AND status = 'loaned'"
        );
        $copy->execute(['id' => (int) $loan['copy_id']]);
        if ($copy->rowCount() !== 1) {
            throw new DomainException(tr('error_copy_checked_out'));
        }
        if ($fine > 0) {
            $insertFine = $pdo->prepare(
                "INSERT INTO fines (loan_id, amount_iqd, status) VALUES (:loan_id, :amount, 'unpaid')"
            );
            $insertFine->execute(['loan_id' => $loanId, 'amount' => $fine]);
        }

        $reservation = $pdo->prepare(
            "SELECT id FROM reservations WHERE book_id = :book_id AND status = 'waiting'
             ORDER BY created_at, id LIMIT 1{$lockClause}"
        );
        $reservation->execute(['book_id' => (int) $bookId]);
        $nextReservation = $reservation->fetchColumn();
        if ($nextReservation !== false) {
            $copyToHold = $pdo->prepare(
                "UPDATE book_copies SET status = 'reserved'
                 WHERE id = :id AND book_id = :book_id AND purpose = 'loan' AND status = 'available'"
            );
            $copyToHold->execute(['id' => (int) $loan['copy_id'], 'book_id' => (int) $bookId]);
            if ($copyToHold->rowCount() !== 1) {
                throw new DomainException(tr('error_copy_checked_out'));
            }

            $readyUntil = $today->modify('+3 days')->format('Y-m-d');
            $holdReservation = $pdo->prepare(
                "UPDATE reservations SET status = 'ready', queue_position = NULL, ready_until = :ready_until
                 WHERE id = :id AND book_id = :book_id AND status = 'waiting'"
            );
            $holdReservation->execute([
                'ready_until' => $readyUntil,
                'id' => (int) $nextReservation,
                'book_id' => (int) $bookId,
            ]);
            if ($holdReservation->rowCount() !== 1) {
                throw new DomainException(tr('reservation_not_cancellable'));
            }

            $waiting = $pdo->prepare(
                "SELECT id FROM reservations WHERE book_id = :book_id AND status = 'waiting'
                 ORDER BY created_at, id{$lockClause}"
            );
            $waiting->execute(['book_id' => (int) $bookId]);
            $updatePosition = $pdo->prepare(
                "UPDATE reservations SET queue_position = :position
                 WHERE id = :id AND book_id = :book_id AND status = 'waiting'"
            );
            foreach ($waiting->fetchAll(PDO::FETCH_COLUMN) as $index => $waitingId) {
                $updatePosition->execute([
                    'position' => $index + 1,
                    'id' => (int) $waitingId,
                    'book_id' => (int) $bookId,
                ]);
            }
            record_activity($pdo, $actorId, 'library.reservation_ready', 'reservation', (int) $nextReservation, [
                'book_id' => (int) $bookId,
                'copy_id' => (int) $loan['copy_id'],
                'ready_until' => $readyUntil,
                'source' => 'return',
            ]);
        }

        record_activity($pdo, $actorId, 'library.return', 'loan', $loanId, ['fine_iqd' => $fine]);
        $pdo->commit();
        return $fine;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function toggle_wishlist(PDO $pdo, int $userId, int $bookId): bool
{
    $existing = $pdo->prepare('SELECT 1 FROM wishlist WHERE user_id = :user_id AND book_id = :book_id');
    $existing->execute(['user_id' => $userId, 'book_id' => $bookId]);
    if ($existing->fetchColumn()) {
        $remove = $pdo->prepare('DELETE FROM wishlist WHERE user_id = :user_id AND book_id = :book_id');
        $remove->execute(['user_id' => $userId, 'book_id' => $bookId]);
        record_activity($pdo, $userId, 'wishlist.remove', 'book', $bookId);
        return false;
    }

    $add = $pdo->prepare('INSERT INTO wishlist (user_id, book_id) VALUES (:user_id, :book_id)');
    $add->execute(['user_id' => $userId, 'book_id' => $bookId]);
    record_activity($pdo, $userId, 'wishlist.add', 'book', $bookId);
    return true;
}
