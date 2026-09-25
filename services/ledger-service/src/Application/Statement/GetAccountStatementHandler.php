<?php

declare(strict_types=1);

namespace App\Application\Statement;

use App\Application\Port\Clock;
use App\Application\Port\StatementLine;
use App\Application\Port\StatementReader;
use App\Domain\Account\AccountRepository;
use App\Domain\Ledger\Exception\InvalidStatementPeriod;
use App\Domain\Shared\MoneyFormatter;
use Money\Money;

final class GetAccountStatementHandler
{
    private const int DEFAULT_DAYS = 30;
    private const int MAX_DAYS = 366;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly StatementReader $reader,
        private readonly Clock $clock,
    ) {}

    public function handle(StatementQuery $query): StatementView
    {
        $account = $this->accounts->get($query->accountId);

        $to = ($query->to ?? $this->clock->now())->setTime(0, 0);
        $from = ($query->from ?? $to->modify(\sprintf('-%d days', self::DEFAULT_DAYS)))->setTime(0, 0);
        if ($from > $to) {
            throw InvalidStatementPeriod::fromAfterTo($from, $to);
        }
        if ((int) $from->diff($to)->days > self::MAX_DAYS) {
            throw InvalidStatementPeriod::tooLong(self::MAX_DAYS);
        }

        $currency = $account->currency();
        $opening = new Money($this->reader->openingBalanceMinor($account->id(), $from), $currency);
        $lines = $this->reader->lines($account->id(), $from, $to->modify('+1 day'));
        $closing = [] === $lines ? $opening : new Money($lines[array_key_last($lines)]->balanceAfterMinor, $currency);

        return new StatementView(
            $account->id()->toRfc4122(),
            $currency->getCode(),
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            MoneyFormatter::format($opening),
            MoneyFormatter::format($closing),
            array_map(static fn(StatementLine $line): StatementLineView => new StatementLineView(
                $line->postingId,
                $line->occurredAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                $line->description,
                $line->transferId,
                $line->direction->value,
                MoneyFormatter::format(new Money($line->amountMinor, $currency)),
                MoneyFormatter::format(new Money($line->balanceAfterMinor, $currency)),
            ), $lines),
        );
    }
}
