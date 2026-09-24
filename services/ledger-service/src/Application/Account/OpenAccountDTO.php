<?php

declare(strict_types=1);

namespace App\Application\Account;

class OpenAccountDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $accountNumber,
        public readonly string $accountType,
        public readonly string $accountBalance,
    ) {
    }
}
