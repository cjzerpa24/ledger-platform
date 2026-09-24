<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Account\Account;
use App\Domain\Shared\MoneyFormatter;

final readonly class AccountView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $currency,
        public string $status,
        public string $balance,
        public string $createdAt,
    ) {}

    public static function fromAccount(Account $account): self
    {
        return new self(
            $account->id()->toRfc4122(),
            $account->name(),
            $account->type()->value,
            $account->currency()->getCode(),
            $account->status()->value,
            MoneyFormatter::format($account->balance()),
            $account->createdAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
        );
    }
}
