<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

interface TransferRepository
{
    public function create(Transfer $transfer): void;
    public function update(Transfer $transfer): void;
    public function findById(Uuid $id): ?Transfer;
    public function findByCriteria(array $criteria): array;
    public function approve(Uuid $id): void;
    public function reject(Uuid $id): void;
}
