<?php
declare(strict_types=1);

/**
 * Permanently remove a title only while it has no retained domain or member
 * references. Available physical copies are removed in the same transaction.
 *
 * @throws DomainException when the title is missing or must be archived.
 */
function delete_unused_book(PDO $pdo, int $bookId, int $actorId): int
{
    if ($bookId < 1 || $actorId < 1) {
        throw new InvalidArgumentException(tr('error_generic'));
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('Title deletion must own its transaction.');
    }

    $lockClause = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();

    try {
        $bookQuery = $pdo->prepare("SELECT id, title FROM books WHERE id = :book_id{$lockClause}");
        $bookQuery->execute(['book_id' => $bookId]);
        $book = $bookQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($book)) {
            throw new DomainException(tr('title_delete_missing'));
        }

        $copyQuery = $pdo->prepare(
            "SELECT id, status FROM book_copies WHERE book_id = :book_id ORDER BY id{$lockClause}"
        );
        $copyQuery->execute(['book_id' => $bookId]);
        $copies = $copyQuery->fetchAll(PDO::FETCH_ASSOC);
        foreach ($copies as $copy) {
            if ($copy['status'] !== 'available') {
                throw new DomainException(tr('title_delete_archive_required'));
            }
        }

        $references = $pdo->prepare(
            'SELECT
                EXISTS (SELECT 1 FROM order_items WHERE book_id = :order_book_id) AS has_orders,
                EXISTS (SELECT 1 FROM loans l JOIN book_copies c ON c.id = l.copy_id WHERE c.book_id = :loan_book_id) AS has_loans,
                EXISTS (SELECT 1 FROM reservations WHERE book_id = :reservation_book_id) AS has_reservations,
                EXISTS (SELECT 1 FROM cart_items WHERE book_id = :cart_book_id) AS has_cart_items,
                EXISTS (SELECT 1 FROM reviews WHERE book_id = :review_book_id) AS has_reviews,
                EXISTS (SELECT 1 FROM wishlist WHERE book_id = :wishlist_book_id) AS has_wishlists'
        );
        $references->execute([
            'order_book_id' => $bookId,
            'loan_book_id' => $bookId,
            'reservation_book_id' => $bookId,
            'cart_book_id' => $bookId,
            'review_book_id' => $bookId,
            'wishlist_book_id' => $bookId,
        ]);
        $referenceState = $references->fetch(PDO::FETCH_ASSOC);
        if (!is_array($referenceState)) {
            throw new DomainException(tr('title_delete_archive_required'));
        }
        foreach ($referenceState as $hasReference) {
            if ((bool) $hasReference) {
                throw new DomainException(tr('title_delete_archive_required'));
            }
        }

        $removeCopies = $pdo->prepare(
            "DELETE FROM book_copies WHERE book_id = :book_id AND status = 'available'"
        );
        $removeCopies->execute(['book_id' => $bookId]);
        if ($removeCopies->rowCount() !== count($copies)) {
            throw new DomainException(tr('title_delete_archive_required'));
        }

        $removeBook = $pdo->prepare('DELETE FROM books WHERE id = :book_id');
        $removeBook->execute(['book_id' => $bookId]);
        if ($removeBook->rowCount() !== 1) {
            throw new DomainException(tr('title_delete_missing'));
        }

        record_activity($pdo, $actorId, 'catalog.delete', 'book', $bookId, [
            'title' => (string) $book['title'],
            'removed_available_copies' => count($copies),
        ]);
        $pdo->commit();

        return count($copies);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
