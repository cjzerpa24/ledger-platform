import { AppConfig, loadConfig } from '../../src/shared/config/app-config';
import { PrismaService } from '../../src/webhooks/infrastructure/persistence/prisma.service';

/** Integration and e2e tests run against the compose Postgres (webhooks_test) and Redis (db 15). */
export function testConfig(overrides: Partial<AppConfig> = {}): AppConfig {
  const databaseUrl = process.env.TEST_DATABASE_URL;
  const redisUrl = process.env.TEST_REDIS_URL;
  if (databaseUrl === undefined || redisUrl === undefined) {
    throw new Error('TEST_DATABASE_URL and TEST_REDIS_URL must be set (run the tests with `make webhook-test`).');
  }

  return {
    ...loadConfig({
      DATABASE_URL: databaseUrl,
      REDIS_URL: redisUrl,
      ROLES: '',
      ALLOW_INSECURE_URLS: 'true',
      ALLOW_PRIVATE_TARGETS: 'true',
      LOG_LEVEL: 'silent',
    }),
    ...overrides,
  };
}

export async function resetDatabase(prisma: PrismaService): Promise<void> {
  await prisma.$executeRawUnsafe('TRUNCATE delivery_attempts, deliveries, subscriptions CASCADE');
}
