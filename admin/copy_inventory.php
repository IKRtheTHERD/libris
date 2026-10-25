<?php
declare(strict_types=1);

/**
 * Mark a physical copy lost or return a previously lost copy to available stock.
 * Checkout, reservation, and loan flows own all other copy status transitions.
 */
function change_copy_inventory_status(PDO $pdo, int $copyId, string $expectedStatus, string $targetStatus, int $actorId): void
{
    if ($copyId < 1 || $actorId < 1) {
        throw new InvalidArgumentException('A valid copy and staff member are required.');
    }
    if (!in_array($targetStatus, ['available', 'lost'], true)) {
        throw new InvalidArgumentException('Staff can only mark a copy available or lost.');
    }
    if (!in_array($expectedStatus, ['available', 'lost'], true)) {
        throw new InvalidArgumentException('The displayed copy status is invalid.');
    }

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT book_id, purpose, status FROM book_copies WHERE id = :id');
        $find->execute(['id' => $copyId]);
        $copy = $find->fetch(PDO::FETCH_ASSOC);
        if (!is_array($copy)) {
            throw new DomainException('The physical copy could not be found.');
        }

        $currentStatus = (string) $copy['status'];
        $purpose = (string) $copy['purpose'];
        if ($currentStatus !== $expectedStatus) {
            throw new DomainException('The physical copy changed. Refresh and try again.');
        }
        if (!in_array($purpose, ['sale', 'loan'], true)
            || !in_array($currentStatus, ['available', 'lost'], true)
            || $currentStatus === $targetStatus
        ) {
            throw new DomainException('This physical copy cannot make that inventory transition.');
        }

        $update = $pdo->prepare(
            'UPDATE book_copies SET status = :next_status WHERE id = :id AND status = :current_status'
        );
        $update->execute([
            'next_status' => $targetStatus,
            'id' => $copyId,
            'current_status' => $currentStatus,
        ]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('The physical copy changed. Refresh and try again.');
        }

        record_activity($pdo, $actorId, 'inventory.copy_status', 'book_copy', $copyId, [
            'book_id' => (int) $copy['book_id'],
            'purpose' => $purpose,
            'from' => $currentStatus,
            'to' => $targetStatus,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
