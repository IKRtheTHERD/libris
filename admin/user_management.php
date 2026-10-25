<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

/**
 * Change a customer account's sign-in status and record the change atomically.
 * Returns false when the requested status is already current.
 */
function set_customer_account_active(PDO $pdo, int $actorId, int $targetId, bool $active): bool
{
    if ($actorId < 1 || $targetId < 1 || $actorId === $targetId) {
        throw new InvalidArgumentException('Invalid account status change.');
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('Account status changes require their own transaction.');
    }

    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $lockClause = in_array($driver, ['mysql', 'pgsql'], true) ? ' FOR UPDATE' : '';

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT role, is_active FROM users WHERE id = :id{$lockClause}");
        $lock->execute(['id' => $targetId]);
        $user = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user) || $user['role'] !== 'customer') {
            throw new DomainException('Customer account not found.');
        }

        $currentActive = (bool) $user['is_active'];
        if ($currentActive === $active) {
            $pdo->commit();
            return false;
        }

        $update = $pdo->prepare(
            'UPDATE users SET is_active = :active
             WHERE id = :id AND role = :role AND is_active = :current_active'
        );
        $update->execute([
            'active' => $active ? 1 : 0,
            'id' => $targetId,
            'role' => 'customer',
            'current_active' => $currentActive ? 1 : 0,
        ]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('Customer account status changed concurrently.');
        }

        record_activity(
            $pdo,
            $actorId,
            'member.' . ($active ? 'activate' : 'deactivate'),
            'user',
            $targetId
        );
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
