<?php

declare(strict_types=1);

namespace App\Application\Port;

interface TransactionManager
{
    /**
     * Runs the operation in one database transaction, flushing pending changes
     * before commit. On any exception the transaction is rolled back, unsaved
     * state is discarded and the exception is rethrown.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws DuplicateRecord when a unique constraint rejects the changes
     */
    public function transactional(callable $operation): mixed;
}
