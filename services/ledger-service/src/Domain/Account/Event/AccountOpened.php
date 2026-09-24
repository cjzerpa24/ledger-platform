<?php

declare(strict_types=1);

namespace App\Domain\Account\Event;

use App\Domain\Account\AccountType;
use App\Domain\Shared\DomainEvent;
use Symfony\Component\Uid\Uuid;

final readonly class AccountOpened implements DomainEvent
{
    public function __construct(
        public Uuid $accountId,
        public string $name,
        public AccountType $type,
        public string $currency,
        public \DateTimeImmutable $openedAt,
    ) {}

    public function eventName(): string
    {
        return 'ledger.account_opened';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function aggregateId(): Uuid
    {
        return $this->accountId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function payload(): array
    {
        return [
            'accountId' => $this->accountId->toRfc4122(),
            'name' => $this->name,
            'type' => $this->type->value,
            'currency' => $this->currency,
            'openedAt' => $this->openedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
