<?php

declare(strict_types=1);

use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use App\Domain\Shared\LedgerException;
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

// Usage: php transfer_worker.php <sourceId> <destinationId> <amount> <idempotencyKey> <startAtUnixFloat>
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
new Dotenv()->bootEnv(dirname(__DIR__, 2) . '/.env');

[, $source, $destination, $amount, $key, $startAt] = $argv;

$kernel = new Kernel('test', false);
$kernel->boot();
// App services are public in the test environment (PublicAppServicesForTestsPass).
$handler = $kernel->getContainer()->get(TransferMoneyHandler::class);

// Boot first, then wait for a shared start time so the workers really overlap.
usleep(max(0, (int) (((float) $startAt - microtime(true)) * 1_000_000)));

try {
    $result = $handler->handle(new TransferMoneyCommand(Uuid::fromString($source), Uuid::fromString($destination), $amount, 'concurrency', $key));
    echo json_encode(['result' => 'ok', 'transferId' => $result->transfer->id, 'replayed' => $result->replayed]);
} catch (LedgerException $e) {
    echo json_encode(['result' => new ReflectionClass($e)->getShortName()]);
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'message' => $e::class . ': ' . $e->getMessage()]);
    exit(1);
}
