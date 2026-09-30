<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Infrastructure\Persistence\Doctrine\BalanceVerifier;
use App\Tests\Support\IntegrationTestCase;

final class DemoSeedCommandTest extends IntegrationTestCase
{
    public function testSeedsOnceAndLeavesTheLedgerBalanced(): void
    {
        $tester = $this->command('ledger:demo:seed');

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Alice (demo)', $tester->getDisplay());
        self::assertSame(3, $this->fetchInt("SELECT COUNT(*) FROM accounts WHERE type = 'customer'"));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already', $tester->getDisplay());
        self::assertSame(3, $this->fetchInt("SELECT COUNT(*) FROM accounts WHERE type = 'customer'"));

        self::assertTrue($this->service(BalanceVerifier::class)->verify()->isClean());
    }
}
