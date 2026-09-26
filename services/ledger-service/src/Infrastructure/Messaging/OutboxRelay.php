<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Port\TransactionManager;
use App\Infrastructure\Persistence\Doctrine\Outbox\OutboxMessage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use App\Application\Port\Clock;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Publishes committed outbox rows in order. Delivery is at-least-once: a
 * crash between dispatch and commit republishes the same eventId.
 */
final class OutboxRelay
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionManager $transactions,
        private readonly MessageBusInterface $bus,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function relay(int $batchSize): RelayResult
    {
        $result = $this->transactions->transactional(function () use ($batchSize): RelayResult {
            $messages = $this->claimBatch(max(1, $batchSize));
            $published = 0;
            $failed = 0;

            foreach ($messages as $message) {
                try {
                    $this->bus->dispatch(IntegrationEvent::fromOutbox($message));
                    $message->markPublished($this->clock->now());
                    ++$published;
                } catch (\Throwable $e) {
                    $message->markFailed($e->getMessage());
                    ++$failed;
                    $this->logger->warning('Outbox publish failed for {id}: {error}', ['id' => $message->id()->toRfc4122(), 'error' => $e->getMessage()]);
                }
            }

            return new RelayResult($published, $failed);
        });

        // Long-running loops must not accumulate managed entities.
        $this->entityManager->clear();

        return $result;
    }

    /** @return list<OutboxMessage> */
    private function claimBatch(int $batchSize): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            \sprintf('SELECT id FROM outbox_messages WHERE published_at IS NULL ORDER BY occurred_at, id LIMIT %d FOR UPDATE SKIP LOCKED', $batchSize),
        );
        if ([] === $ids) {
            return [];
        }

        $result = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(OutboxMessage::class, 'm')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', array_map(static function (mixed $id): string {
                \assert(\is_string($id));

                return $id;
            }, $ids), ArrayParameterType::STRING)
            ->orderBy('m.occurredAt')
            ->addOrderBy('m.id')
            ->getQuery()
            ->getResult();
        \assert(\is_array($result));

        $messages = [];
        foreach ($result as $message) {
            \assert($message instanceof OutboxMessage);
            $messages[] = $message;
        }

        return $messages;
    }
}
