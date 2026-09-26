import { INestApplication } from '@nestjs/common';
import { Test } from '@nestjs/testing';
import Redis from 'ioredis';
import { AppModule } from '../../src/app.module';
import { APP_CONFIG, AppConfig } from '../../src/shared/config/app-config';
import { configureHttp } from '../../src/shared/configure-http';
import { DispatchDueDeliveries } from '../../src/webhooks/application/dispatch-due-deliveries';
import { REDIS } from '../../src/webhooks/infrastructure/messaging/redis';
import { RedisStreamConsumer } from '../../src/webhooks/infrastructure/messaging/redis-stream-consumer';
import { PrismaService } from '../../src/webhooks/infrastructure/persistence/prisma.service';
import { testConfig } from './environment';

export interface TestApp {
  app: INestApplication;
  config: AppConfig;
  prisma: PrismaService;
  redis: Redis;
  consumer: RedisStreamConsumer;
  dispatch: DispatchDueDeliveries;
}

/** The real AppModule with no background loops (ROLES empty); tests drive the consumer and dispatcher directly. */
export async function createTestApp(overrides: Partial<AppConfig> = {}): Promise<TestApp> {
  const config = testConfig(overrides);
  const moduleRef = await Test.createTestingModule({ imports: [AppModule] })
    .overrideProvider(APP_CONFIG)
    .useValue(config)
    .compile();
  const app = moduleRef.createNestApplication({ logger: false });
  configureHttp(app);
  await app.init();

  return {
    app,
    config,
    prisma: app.get(PrismaService),
    redis: app.get<Redis>(REDIS),
    consumer: app.get(RedisStreamConsumer),
    dispatch: app.get(DispatchDueDeliveries),
  };
}
