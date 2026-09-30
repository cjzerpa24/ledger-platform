<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Application\Transfer\RecordWithdrawalCommand;
use App\Application\Transfer\RecordWithdrawalHandler;
use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use App\Domain\Shared\LedgerException;
use App\Infrastructure\Persistence\Doctrine\BalanceVerifier;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\Uid\Uuid;

final class LedgerInvariantTest extends IntegrationTestCase
{
    public function testBalancesMatchPostingsAndSumToZeroAfterMixedActivity(): void
    {
        $usd = array_map(fn(string $n): Uuid => $this->open($n, 'USD'), ['A', 'B', 'C']);
        $jpy = $this->open('Yen', 'JPY');
        $step = 0;
        $run = function (callable $operation) use (&$step): void {
            ++$step;
            try {
                $operation("inv-{$step}");
            } catch (LedgerException) {
                // Rejected operations are part of the script; they must leave no trace.
            }
        };

        $run(fn(string $k) => $this->service(RecordDepositHandler::class)->handle(new RecordDepositCommand($usd[0], '500.00', null, $k)));
        $run(fn(string $k) => $this->service(RecordDepositHandler::class)->handle(new RecordDepositCommand($jpy, '12000', null, $k)));
        $run(fn(string $k) => $this->service(TransferMoneyHandler::class)->handle(new TransferMoneyCommand($usd[0], $usd[1], '120.25', null, $k)));
        $run(fn(string $k) => $this->service(TransferMoneyHandler::class)->handle(new TransferMoneyCommand($usd[1], $usd[2], '20.25', null, $k)));
        $run(fn(string $k) => $this->service(TransferMoneyHandler::class)->handle(new TransferMoneyCommand($usd[2], $usd[0], '999.00', null, $k)));
        $run(fn(string $k) => $this->service(RecordWithdrawalHandler::class)->handle(new RecordWithdrawalCommand($usd[1], '50.00', null, $k)));
        $run(fn(string $k) => $this->service(RecordWithdrawalHandler::class)->handle(new RecordWithdrawalCommand($jpy, '500', null, $k)));
        $run(fn(string $k) => $this->service(TransferMoneyHandler::class)->handle(new TransferMoneyCommand($jpy, $usd[0], '1', null, $k)));

        $report = $this->service(BalanceVerifier::class)->verify();

        self::assertTrue($report->isClean(), json_encode($report, \JSON_THROW_ON_ERROR));
        self::assertSame(['379.75', '50.00', '20.25'], array_map(fn(Uuid $id): string => $this->balance($id), $usd));
    }

    public function testVerifierAndCommandReportACorruptedBalance(): void
    {
        $account = $this->open('Victim', 'USD');
        $this->service(RecordDepositHandler::class)->handle(new RecordDepositCommand($account, '10.00', null, 'corrupt'));
        $this->connection()->executeStatement('UPDATE accounts SET balance_minor = balance_minor + 1 WHERE id = :id', ['id' => $account->toRfc4122()]);

        $report = $this->service(BalanceVerifier::class)->verify();
        self::assertFalse($report->isClean());
        self::assertSame([['accountId' => $account->toRfc4122(), 'cachedMinor' => 1001, 'computedMinor' => 1000]], $report->accountMismatches);
        self::assertSame([['currency' => 'USD', 'totalMinor' => 1]], $report->currencyImbalances);

        $tester = $this->command('ledger:verify-balances');
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString($account->toRfc4122(), $tester->getDisplay());
    }

    private function open(string $name, string $currency): Uuid
    {
        return Uuid::fromString($this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand($name, $currency))->id);
    }

    private function balance(Uuid $id): string
    {
        return $this->service(\App\Application\Account\GetAccountHandler::class)->handle($id)->balance;
    }
}
