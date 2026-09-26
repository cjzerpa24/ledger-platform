<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Transfer\Exception\TransferNotFound;
use Symfony\Component\Uid\Uuid;

interface TransferRepository
{
    public function add(Transfer $transfer): void;

    /** @throws TransferNotFound */
    public function get(Uuid $id): Transfer;

    public function findByIdempotencyKey(string $idempotencyKey): ?Transfer;
}
