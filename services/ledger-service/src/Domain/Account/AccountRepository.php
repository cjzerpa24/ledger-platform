<?php

declare(strict_types=1);

namespace App\Domain\Account;

interface AccountRepository
{
    public function create(Account $account): void;
    public function update(Account $account): void;
    public function findById(Uuid $id): ?Account;
    public function findByCriteria(array $criteria): array;
    public function inactivate(Uuid $id): void;
    public function activate(Uuid $id): void;
}
