import { hostname } from 'node:os';
import { z } from 'zod';

export const APP_CONFIG = Symbol('AppConfig');

const ROLES = ['api', 'consumer', 'dispatcher'] as const;
export type Role = (typeof ROLES)[number];

const positiveInt = (fallback: number) => z.coerce.number().int().positive().default(fallback);
const flag = z.stringbool().default(false);

const schema = z.object({
  PORT: positiveInt(3000),
  DATABASE_URL: z.string().startsWith('postgresql://'),
  REDIS_URL: z.string().startsWith('redis://'),
  LEDGER_STREAM: z.string().min(1).default('ledger_events'),
  CONSUMER_GROUP: z.string().min(1).default('webhook-service'),
  CONSUMER_NAME: z.string().min(1).default(hostname()),
  ROLES: z
    .string()
    .default(ROLES.join(','))
    .transform((value) =>
      value
        .split(',')
        .map((role) => role.trim())
        .filter((role) => role !== ''),
    )
    .pipe(z.array(z.enum(ROLES))),
  ALLOW_INSECURE_URLS: flag,
  ALLOW_PRIVATE_TARGETS: flag,
  DELIVERY_TIMEOUT_MS: positiveInt(10_000),
  DISPATCH_INTERVAL_MS: positiveInt(1_000),
  LEASE_MS: positiveInt(60_000),
  LOG_LEVEL: z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent']).default('info'),
});

export interface AppConfig {
  port: number;
  databaseUrl: string;
  redisUrl: string;
  ledgerStream: string;
  consumerGroup: string;
  consumerName: string;
  roles: Role[];
  allowInsecureUrls: boolean;
  allowPrivateTargets: boolean;
  deliveryTimeoutMs: number;
  dispatchIntervalMs: number;
  leaseMs: number;
  logLevel: string;
}

/** Validates the environment; throws with every problem listed so a bad deploy fails at startup. */
export function loadConfig(env: Record<string, string | undefined>): AppConfig {
  const parsed = schema.safeParse(env);
  if (!parsed.success) {
    const problems = parsed.error.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`);
    throw new Error(`Invalid configuration:\n  ${problems.join('\n  ')}`);
  }
  const e = parsed.data;

  return {
    port: e.PORT,
    databaseUrl: e.DATABASE_URL,
    redisUrl: e.REDIS_URL,
    ledgerStream: e.LEDGER_STREAM,
    consumerGroup: e.CONSUMER_GROUP,
    consumerName: e.CONSUMER_NAME,
    roles: e.ROLES,
    allowInsecureUrls: e.ALLOW_INSECURE_URLS,
    allowPrivateTargets: e.ALLOW_PRIVATE_TARGETS,
    deliveryTimeoutMs: e.DELIVERY_TIMEOUT_MS,
    dispatchIntervalMs: e.DISPATCH_INTERVAL_MS,
    leaseMs: e.LEASE_MS,
    logLevel: e.LOG_LEVEL,
  };
}
