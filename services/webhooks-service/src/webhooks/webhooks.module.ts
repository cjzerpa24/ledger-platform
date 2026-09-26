import { Inject, Logger, Module, OnApplicationShutdown, Provider } from '@nestjs/common';
import Redis from 'ioredis';
import { APP_CONFIG, AppConfig } from '../shared/config/app-config';
import { PauseSubscription, ResumeSubscription } from './application/change-subscription-status';
import { DispatchDueDeliveries } from './application/dispatch-due-deliveries';
import { GetDelivery } from './application/get-delivery';
import { GetSubscription } from './application/get-subscription';
import { IngestLedgerEvent } from './application/ingest-ledger-event';
import { ListDeliveries } from './application/list-deliveries';
import { ListSubscriptions } from './application/list-subscriptions';
import { CLOCK, Clock } from './application/ports/clock';
import { HTTP_SENDER, HttpSender } from './application/ports/http-sender';
import { SECRET_GENERATOR, SecretGenerator } from './application/ports/secret-generator';
import { SIGNER, Signer } from './application/ports/signer';
import { RegisterSubscription } from './application/register-subscription';
import { RetryDelivery } from './application/retry-delivery';
import { DELIVERY_REPOSITORY, DeliveryRepository } from './domain/delivery/delivery-repository';
import { RetryPolicy } from './domain/delivery/retry-policy';
import { SUBSCRIPTION_REPOSITORY, SubscriptionRepository } from './domain/subscription/subscription-repository';
import { DispatcherLoop } from './infrastructure/delivery/dispatcher-loop';
import { NodeHttpSender } from './infrastructure/delivery/node-http-sender';
import { StandardWebhooksSigner } from './infrastructure/delivery/standard-webhooks-signer';
import { DeliveriesController } from './infrastructure/http/deliveries.controller';
import { HealthController } from './infrastructure/http/health.controller';
import { SubscriptionsController } from './infrastructure/http/subscriptions.controller';
import { MessengerEnvelopeDecoder } from './infrastructure/messaging/messenger-envelope-decoder';
import { createRedis, REDIS } from './infrastructure/messaging/redis';
import { RedisStreamConsumer } from './infrastructure/messaging/redis-stream-consumer';
import { StreamConsumerRunner } from './infrastructure/messaging/stream-consumer-runner';
import { PrismaDeliveryRepository } from './infrastructure/persistence/prisma-delivery-repository';
import { PrismaSubscriptionRepository } from './infrastructure/persistence/prisma-subscription-repository';
import { PrismaService } from './infrastructure/persistence/prisma.service';
import { CryptoSecretGenerator } from './infrastructure/system/crypto-secret-generator';
import { SystemClock } from './infrastructure/system/system-clock';

const DISPATCH_BATCH_SIZE = 20;
/** How often the consumer looks for entries stranded by other consumers (see CLAIM_IDLE_MS). */
const CLAIM_INTERVAL_MS = 30_000;

/** Use cases are plain classes; Nest only wires their ports here. */
const factory = <T>(provide: unknown, inject: unknown[], useFactory: (...deps: never[]) => T): Provider => ({
  provide: provide as never,
  inject: inject as never[],
  useFactory,
});

const infrastructure: Provider[] = [
  factory(PrismaService, [APP_CONFIG], (config: AppConfig) => new PrismaService(config.databaseUrl)),
  factory(REDIS, [APP_CONFIG], (config: AppConfig) => createRedis(config.redisUrl)),
  factory(
    SUBSCRIPTION_REPOSITORY,
    [PrismaService],
    (prisma: PrismaService) => new PrismaSubscriptionRepository(prisma),
  ),
  factory(DELIVERY_REPOSITORY, [PrismaService], (prisma: PrismaService) => new PrismaDeliveryRepository(prisma)),
  factory(CLOCK, [], () => new SystemClock()),
  factory(SECRET_GENERATOR, [], () => new CryptoSecretGenerator()),
  factory(SIGNER, [], () => new StandardWebhooksSigner()),
  factory(
    HTTP_SENDER,
    [APP_CONFIG],
    (config: AppConfig) => new NodeHttpSender({ allowPrivateTargets: config.allowPrivateTargets }),
  ),
];

const useCases: Provider[] = [
  factory(
    RegisterSubscription,
    [SUBSCRIPTION_REPOSITORY, SECRET_GENERATOR, CLOCK, APP_CONFIG],
    (repo: SubscriptionRepository, secrets: SecretGenerator, clock: Clock, config: AppConfig) =>
      new RegisterSubscription(repo, secrets, clock, {
        allowInsecure: config.allowInsecureUrls,
        allowPrivate: config.allowPrivateTargets,
      }),
  ),
  factory(ListSubscriptions, [SUBSCRIPTION_REPOSITORY], (repo: SubscriptionRepository) => new ListSubscriptions(repo)),
  factory(GetSubscription, [SUBSCRIPTION_REPOSITORY], (repo: SubscriptionRepository) => new GetSubscription(repo)),
  factory(PauseSubscription, [SUBSCRIPTION_REPOSITORY], (repo: SubscriptionRepository) => new PauseSubscription(repo)),
  factory(
    ResumeSubscription,
    [SUBSCRIPTION_REPOSITORY],
    (repo: SubscriptionRepository) => new ResumeSubscription(repo),
  ),
  factory(
    ListDeliveries,
    [SUBSCRIPTION_REPOSITORY, DELIVERY_REPOSITORY],
    (subs: SubscriptionRepository, deliveries: DeliveryRepository) => new ListDeliveries(subs, deliveries),
  ),
  factory(GetDelivery, [DELIVERY_REPOSITORY], (deliveries: DeliveryRepository) => new GetDelivery(deliveries)),
  factory(
    RetryDelivery,
    [DELIVERY_REPOSITORY, CLOCK],
    (deliveries: DeliveryRepository, clock: Clock) => new RetryDelivery(deliveries, clock),
  ),
  factory(
    IngestLedgerEvent,
    [SUBSCRIPTION_REPOSITORY, DELIVERY_REPOSITORY, CLOCK],
    (subs: SubscriptionRepository, deliveries: DeliveryRepository, clock: Clock) =>
      new IngestLedgerEvent(subs, deliveries, clock),
  ),
  factory(
    DispatchDueDeliveries,
    [DELIVERY_REPOSITORY, SUBSCRIPTION_REPOSITORY, HTTP_SENDER, SIGNER, CLOCK, APP_CONFIG],
    (
      deliveries: DeliveryRepository,
      subs: SubscriptionRepository,
      sender: HttpSender,
      signer: Signer,
      clock: Clock,
      config: AppConfig,
    ) =>
      new DispatchDueDeliveries(deliveries, subs, sender, signer, clock, {
        batchSize: DISPATCH_BATCH_SIZE,
        leaseMs: config.leaseMs,
        timeoutMs: config.deliveryTimeoutMs,
        policy: RetryPolicy.DEFAULT,
      }),
  ),
];

const background: Provider[] = [
  factory(
    RedisStreamConsumer,
    [REDIS, IngestLedgerEvent, APP_CONFIG],
    (redis: Redis, ingest: IngestLedgerEvent, config: AppConfig) =>
      // A blocking XREADGROUP holds its connection, so the consumer gets its own.
      new RedisStreamConsumer(
        redis.duplicate(),
        ingest,
        new MessengerEnvelopeDecoder(),
        {
          stream: config.ledgerStream,
          group: config.consumerGroup,
          consumer: config.consumerName,
          count: 50,
          blockMs: 5_000,
          retryDelayMs: 1_000,
          claimIdleMs: config.claimIdleMs,
          claimIntervalMs: CLAIM_INTERVAL_MS,
        },
        new Logger(RedisStreamConsumer.name),
      ),
  ),
  factory(
    StreamConsumerRunner,
    [RedisStreamConsumer, APP_CONFIG],
    (consumer: RedisStreamConsumer, config: AppConfig) =>
      new StreamConsumerRunner(consumer, config.roles.includes('consumer')),
  ),
  factory(
    DispatcherLoop,
    [DispatchDueDeliveries, APP_CONFIG],
    (dispatch: DispatchDueDeliveries, config: AppConfig) =>
      new DispatcherLoop(dispatch, {
        enabled: config.roles.includes('dispatcher'),
        intervalMs: config.dispatchIntervalMs,
        batchSize: DISPATCH_BATCH_SIZE,
      }),
  ),
];

@Module({
  controllers: [SubscriptionsController, DeliveriesController, HealthController],
  providers: [...infrastructure, ...useCases, ...background],
  exports: [PrismaService, REDIS, IngestLedgerEvent, DispatchDueDeliveries, RedisStreamConsumer],
})
export class WebhooksModule implements OnApplicationShutdown {
  constructor(@Inject(REDIS) private readonly redis: Redis) {}

  async onApplicationShutdown(): Promise<void> {
    await this.redis.quit().catch(() => undefined);
  }
}
