<?php
declare(strict_types=1);

/**
 * Apply a staff fulfillment or refund action while keeping order, payment,
 * physical-copy inventory, and activity history in one transaction.
 */
function apply_staff_order_action(
    PDO $pdo,
    int $orderId,
    string $action,
    ?string $requestedStatus,
    int $actorId
): string {
    if ($orderId < 1 || $actorId < 1) {
        throw new InvalidArgumentException(tr('error_generic'));
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('Staff order actions must own their transaction.');
    }

    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $nextStatus = [
        'paid' => 'processing',
        'processing' => 'ready',
        'ready' => 'completed',
    ];

    $pdo->beginTransaction();
    try {
        $orderQuery = $pdo->prepare(
            'SELECT status, total_iqd FROM orders WHERE id = :id' . $lockClause
        );
        $orderQuery->execute(['id' => $orderId]);
        $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            throw new DomainException(tr('invalid_order_transition'));
        }

        $currentStatus = (string) $order['status'];
        if ($action === 'advance') {
            $targetStatus = $nextStatus[$currentStatus] ?? null;
            if ($targetStatus === null || $requestedStatus !== $targetStatus) {
                throw new DomainException(tr('invalid_order_transition'));
            }
        } elseif ($action === 'refund') {
            if (!in_array($currentStatus, ['paid', 'processing', 'ready'], true)) {
                throw new DomainException(tr('invalid_order_transition'));
            }
            $targetStatus = 'refunded';
            refund_staff_order_inventory($pdo, $orderId, (int) $order['total_iqd'], $lockClause);
        } else {
            throw new InvalidArgumentException(tr('error_generic'));
        }

        $updateOrder = $pdo->prepare(
            'UPDATE orders SET status = :status WHERE id = :id AND status = :current_status'
        );
        $updateOrder->execute([
            'status' => $targetStatus,
            'id' => $orderId,
            'current_status' => $currentStatus,
        ]);
        if ($updateOrder->rowCount() !== 1) {
            throw new DomainException(tr('invalid_order_transition'));
        }

        record_activity(
            $pdo,
            $actorId,
            'order.status',
            'order',
            $orderId,
            [
                'from_status' => $currentStatus,
                'to_status' => $targetStatus,
                'action' => $action,
            ]
        );
        $pdo->commit();
        return $targetStatus;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Restore only the exact paid sale copies referenced by this order. */
function refund_staff_order_inventory(PDO $pdo, int $orderId, int $totalIqd, string $lockClause): void
{
    $paymentQuery = $pdo->prepare(
        "SELECT id, amount_iqd FROM payments
         WHERE order_id = :order_id AND status = 'succeeded'
         ORDER BY id" . $lockClause
    );
    $paymentQuery->execute(['order_id' => $orderId]);
    $payments = $paymentQuery->fetchAll(PDO::FETCH_ASSOC);
    if (count($payments) !== 1 || (int) $payments[0]['amount_iqd'] !== $totalIqd) {
        throw new RuntimeException('Refund rejected because the order payment state is inconsistent.');
    }

    $itemsQuery = $pdo->prepare(
        'SELECT oi.book_id, oi.copy_id, oi.quantity,
                c.book_id AS copy_book_id, c.purpose, c.status AS copy_status,
                (SELECT COUNT(*) FROM order_items other WHERE other.copy_id = oi.copy_id) AS copy_references
         FROM order_items oi
         LEFT JOIN book_copies c ON c.id = oi.copy_id
         WHERE oi.order_id = :order_id
         ORDER BY oi.id' . $lockClause
    );
    $itemsQuery->execute(['order_id' => $orderId]);
    $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
    if ($items === []) {
        throw new RuntimeException('Refund rejected because the order has no inventory lines.');
    }

    $copies = [];
    foreach ($items as $item) {
        if (
            $item['copy_id'] === null
            || (int) $item['quantity'] !== 1
            || $item['copy_book_id'] === null
            || (int) $item['book_id'] !== (int) $item['copy_book_id']
            || $item['purpose'] !== 'sale'
            || $item['copy_status'] !== 'sold'
            || (int) $item['copy_references'] !== 1
        ) {
            throw new RuntimeException('Refund rejected because order inventory is inconsistent.');
        }
        $copies[] = (int) $item['copy_id'];
    }

    $restoreCopy = $pdo->prepare(
        "UPDATE book_copies SET status = 'available'
         WHERE id = :copy_id AND purpose = 'sale' AND status = 'sold'"
    );
    foreach ($copies as $copyId) {
        $restoreCopy->execute(['copy_id' => $copyId]);
        if ($restoreCopy->rowCount() !== 1) {
            throw new RuntimeException('Refund rejected because a sold copy changed concurrently.');
        }
    }

    $refundPayment = $pdo->prepare(
        "UPDATE payments SET status = 'refunded', processed_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status = 'succeeded'"
    );
    $refundPayment->execute(['id' => (int) $payments[0]['id']]);
    if ($refundPayment->rowCount() !== 1) {
        throw new RuntimeException('Refund rejected because the payment changed concurrently.');
    }
}
