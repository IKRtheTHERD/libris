<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/library.php';

function library_operations_lock_clause(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

function library_operations_assert_owns_transaction(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Library operations must own their transaction.');
    }
}

function library_operations_current_date(PDO $pdo): DateTimeImmutable
{
    return new DateTimeImmutable((string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn());
}

function compact_staff_reservation_queue(PDO $pdo, int $bookId): void
{
    $lockClause = library_operations_lock_clause($pdo);
    $waiting = $pdo->prepare(
        "SELECT id FROM reservations WHERE book_id = :book_id AND status = 'waiting'
         ORDER BY created_at, id{$lockClause}"
    );
    $waiting->execute(['book_id' => $bookId]);
    $update = $pdo->prepare(
        "UPDATE reservations SET queue_position = :position
         WHERE id = :id AND book_id = :book_id AND status = 'waiting'"
    );
    foreach ($waiting->fetchAll(PDO::FETCH_COLUMN) as $index => $reservationId) {
        $update->execute([
            'position' => $index + 1,
            'id' => (int) $reservationId,
            'book_id' => $bookId,
        ]);
    }
}

function promote_next_staff_reservation(PDO $pdo, int $bookId, int $actorId, ?int $expectedId = null): ?int
{
    $lockClause = library_operations_lock_clause($pdo);
    $firstWaiting = $pdo->prepare(
        "SELECT id FROM reservations WHERE book_id = :book_id AND status = 'waiting'
         ORDER BY created_at, id LIMIT 1{$lockClause}"
    );
    $firstWaiting->execute(['book_id' => $bookId]);
    $reservationId = $firstWaiting->fetchColumn();
    if ($reservationId === false) {
        return null;
    }
    $reservationId = (int) $reservationId;
    if ($expectedId !== null && $reservationId !== $expectedId) {
        throw new DomainException(tr('error_reservation_changed'));
    }

    $availableCopy = $pdo->prepare(
        "SELECT id FROM book_copies
         WHERE book_id = :book_id AND purpose = 'loan' AND status = 'available'
         ORDER BY id LIMIT 1{$lockClause}"
    );
    $availableCopy->execute(['book_id' => $bookId]);
    $copyId = $availableCopy->fetchColumn();
    if ($copyId === false) {
        return null;
    }
    $copyId = (int) $copyId;

    $reserveCopy = $pdo->prepare(
        "UPDATE book_copies SET status = 'reserved'
         WHERE id = :id AND book_id = :book_id AND purpose = 'loan' AND status = 'available'"
    );
    $reserveCopy->execute(['id' => $copyId, 'book_id' => $bookId]);
    if ($reserveCopy->rowCount() !== 1) {
        throw new DomainException(tr('error_copy_checked_out'));
    }

    $readyUntil = library_operations_current_date($pdo)->modify('+3 days')->format('Y-m-d');
    $ready = $pdo->prepare(
        "UPDATE reservations SET status = 'ready', queue_position = NULL, ready_until = :ready_until
         WHERE id = :id AND book_id = :book_id AND status = 'waiting'"
    );
    $ready->execute([
        'ready_until' => $readyUntil,
        'id' => $reservationId,
        'book_id' => $bookId,
    ]);
    if ($ready->rowCount() !== 1) {
        throw new DomainException(tr('error_reservation_changed'));
    }

    compact_staff_reservation_queue($pdo, $bookId);
    record_activity($pdo, $actorId, 'library.reservation_ready', 'reservation', $reservationId, [
        'book_id' => $bookId,
        'copy_id' => $copyId,
        'ready_until' => $readyUntil,
        'source' => 'staff',
    ]);

    return $reservationId;
}

/** Mark an active loan and its checked-out physical copy as lost atomically. */
function mark_loan_copy_lost(PDO $pdo, int $loanId, int $actorId): void
{
    if ($loanId < 1 || $actorId < 1) {
        throw new InvalidArgumentException('A valid loan and staff actor are required.');
    }
    library_operations_assert_owns_transaction($pdo);
    $lockClause = library_operations_lock_clause($pdo);
    $pdo->beginTransaction();
    try {
        $bookQuery = $pdo->prepare(
            'SELECT c.book_id FROM loans l JOIN book_copies c ON c.id = l.copy_id WHERE l.id = :id'
        );
        $bookQuery->execute(['id' => $loanId]);
        $bookId = $bookQuery->fetchColumn();
        if ($bookId === false) {
            throw new DomainException(tr('error_loan_not_found'));
        }

        $bookLock = $pdo->prepare("SELECT id FROM books WHERE id = :id{$lockClause}");
        $bookLock->execute(['id' => (int) $bookId]);
        if ($bookLock->fetchColumn() === false) {
            throw new DomainException(tr('error_title_unavailable'));
        }

        $loanQuery = $pdo->prepare(
            "SELECT l.copy_id, c.book_id, c.purpose, c.status AS copy_status
             FROM loans l JOIN book_copies c ON c.id = l.copy_id
             WHERE l.id = :id AND l.status = 'active'{$lockClause}"
        );
        $loanQuery->execute(['id' => $loanId]);
        $loan = $loanQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($loan)) {
            throw new DomainException(tr('error_loan_not_found'));
        }
        if ($loan['purpose'] !== 'loan' || $loan['copy_status'] !== 'loaned') {
            throw new DomainException(tr('error_copy_checked_out'));
        }

        $updateLoan = $pdo->prepare(
            "UPDATE loans SET status = 'lost' WHERE id = :id AND status = 'active'"
        );
        $updateLoan->execute(['id' => $loanId]);
        $updateCopy = $pdo->prepare(
            "UPDATE book_copies SET status = 'lost'
             WHERE id = :id AND purpose = 'loan' AND status = 'loaned'"
        );
        $updateCopy->execute(['id' => (int) $loan['copy_id']]);
        if ($updateLoan->rowCount() !== 1 || $updateCopy->rowCount() !== 1) {
            throw new DomainException(tr('error_copy_checked_out'));
        }

        record_activity($pdo, $actorId, 'library.loan_lost', 'loan', $loanId, [
            'book_id' => (int) $loan['book_id'],
            'copy_id' => (int) $loan['copy_id'],
            'previous_loan_status' => 'active',
            'copy_status' => 'lost',
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Operate on one reservation with queue priority and physical-copy state kept in sync. */
function operate_staff_reservation(
    PDO $pdo,
    int $reservationId,
    string $expectedStatus,
    string $action,
    int $actorId
): array {
    if ($reservationId < 1 || $actorId < 1 || !in_array($expectedStatus, ['waiting', 'ready'], true)
        || !in_array($action, ['prepare', 'cancel', 'expire'], true)) {
        throw new InvalidArgumentException('Invalid staff reservation operation.');
    }
    library_operations_assert_owns_transaction($pdo);
    $lockClause = library_operations_lock_clause($pdo);
    $pdo->beginTransaction();
    try {
        $bookQuery = $pdo->prepare('SELECT book_id FROM reservations WHERE id = :id');
        $bookQuery->execute(['id' => $reservationId]);
        $bookId = $bookQuery->fetchColumn();
        if ($bookId === false) {
            throw new DomainException(tr('error_reservation_changed'));
        }
        $bookId = (int) $bookId;
        $bookLock = $pdo->prepare("SELECT id FROM books WHERE id = :id{$lockClause}");
        $bookLock->execute(['id' => $bookId]);
        if ($bookLock->fetchColumn() === false) {
            throw new DomainException(tr('error_title_unavailable'));
        }

        $reservationQuery = $pdo->prepare(
            "SELECT id, user_id, book_id, status, queue_position, ready_until
             FROM reservations WHERE id = :id AND book_id = :book_id{$lockClause}"
        );
        $reservationQuery->execute(['id' => $reservationId, 'book_id' => $bookId]);
        $reservation = $reservationQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($reservation) || $reservation['status'] !== $expectedStatus) {
            throw new DomainException(tr('error_reservation_changed'));
        }

        if ($action === 'prepare') {
            if ($expectedStatus !== 'waiting') {
                throw new DomainException(tr('error_reservation_changed'));
            }
            $promotedId = promote_next_staff_reservation($pdo, $bookId, $actorId, $reservationId);
            if ($promotedId === null) {
                throw new DomainException(tr('error_no_loan_copies'));
            }
            $pdo->commit();
            return ['action' => 'prepared', 'promoted_reservation_id' => null];
        }

        $terminalStatus = $action === 'expire' ? 'expired' : 'cancelled';
        if ($action === 'expire') {
            $today = library_operations_current_date($pdo)->format('Y-m-d');
            if ($expectedStatus !== 'ready' || !is_string($reservation['ready_until'])
                || $reservation['ready_until'] >= $today) {
                throw new DomainException(tr('error_reservation_changed'));
            }
        }

        $copyId = null;
        if ($expectedStatus === 'ready') {
            $reservedCopy = $pdo->prepare(
                "SELECT id FROM book_copies
                 WHERE book_id = :book_id AND purpose = 'loan' AND status = 'reserved'
                 ORDER BY id LIMIT 1{$lockClause}"
            );
            $reservedCopy->execute(['book_id' => $bookId]);
            $copyId = $reservedCopy->fetchColumn();
            if ($copyId === false) {
                throw new DomainException(tr('error_reserved_copy_unavailable'));
            }
            $copyId = (int) $copyId;
        }

        $updateReservation = $pdo->prepare(
            'UPDATE reservations SET status = :status, queue_position = NULL
             WHERE id = :id AND book_id = :book_id AND status = :expected_status'
        );
        $updateReservation->execute([
            'status' => $terminalStatus,
            'id' => $reservationId,
            'book_id' => $bookId,
            'expected_status' => $expectedStatus,
        ]);
        if ($updateReservation->rowCount() !== 1) {
            throw new DomainException(tr('error_reservation_changed'));
        }

        $promotedId = null;
        if ($copyId !== null) {
            $releaseCopy = $pdo->prepare(
                "UPDATE book_copies SET status = 'available'
                 WHERE id = :id AND book_id = :book_id AND purpose = 'loan' AND status = 'reserved'"
            );
            $releaseCopy->execute(['id' => $copyId, 'book_id' => $bookId]);
            if ($releaseCopy->rowCount() !== 1) {
                throw new DomainException(tr('error_reserved_copy_unavailable'));
            }
            $promotedId = promote_next_staff_reservation($pdo, $bookId, $actorId);
        } else {
            compact_staff_reservation_queue($pdo, $bookId);
        }

        record_activity(
            $pdo,
            $actorId,
            $action === 'expire' ? 'library.reservation_expire' : 'library.reservation_cancel',
            'reservation',
            $reservationId,
            [
                'book_id' => $bookId,
                'copy_id' => $copyId,
                'previous_status' => $expectedStatus,
                'status' => $terminalStatus,
                'promoted_reservation_id' => $promotedId,
            ]
        );
        $pdo->commit();
        return ['action' => $terminalStatus, 'promoted_reservation_id' => $promotedId];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Record cash collection or waive an unpaid fine without processing a payment. */
function settle_staff_fine(PDO $pdo, int $fineId, string $outcome, int $actorId): bool
{
    if ($fineId < 1 || $actorId < 1 || !in_array($outcome, ['paid', 'waived'], true)) {
        throw new InvalidArgumentException('Invalid fine settlement.');
    }
    library_operations_assert_owns_transaction($pdo);
    $lockClause = library_operations_lock_clause($pdo);
    $pdo->beginTransaction();
    try {
        $fineQuery = $pdo->prepare(
            "SELECT loan_id, amount_iqd FROM fines WHERE id = :id AND status = 'unpaid'{$lockClause}"
        );
        $fineQuery->execute(['id' => $fineId]);
        $fine = $fineQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($fine)) {
            throw new DomainException(tr('error_fine_not_unpaid'));
        }

        $update = $pdo->prepare(
            "UPDATE fines SET status = :status, settled_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'unpaid'"
        );
        $update->execute(['status' => $outcome, 'id' => $fineId]);
        if ($update->rowCount() !== 1) {
            throw new DomainException(tr('error_fine_not_unpaid'));
        }
        record_activity($pdo, $actorId, 'library.fine_' . $outcome, 'fine', $fineId, [
            'loan_id' => (int) $fine['loan_id'],
            'amount_iqd' => (int) $fine['amount_iqd'],
            'status' => $outcome,
        ]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
