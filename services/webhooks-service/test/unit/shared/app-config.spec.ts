import { loadConfig } from '../../../src/shared/config/app-config';

const required = { DATABASE_URL: 'postgresql://u:p@db:5432/webhooks', REDIS_URL: 'redis://redis:6379' };

describe('loadConfig', () => {
  it('applies defaults', () => {
    const config = loadConfig(required);

    expect(config).toMatchObject({
      port: 3000,
      ledgerStream: 'ledger_events',
      consumerGroup: 'webhook-service',
      roles: ['api', 'consumer', 'dispatcher'],
      allowInsecureUrls: false,
      allowPrivateTargets: false,
      deliveryTimeoutMs: 10_000,
      dispatchIntervalMs: 1_000,
      leaseMs: 60_000,
    });
    expect(config.consumerName.length).toBeGreaterThan(0);
  });

  it('parses roles, flags and numbers', () => {
    const config = loadConfig({
      ...required,
      ROLES: 'api, dispatcher',
      ALLOW_INSECURE_URLS: 'true',
      DELIVERY_TIMEOUT_MS: '2500',
    });

    expect(config.roles).toEqual(['api', 'dispatcher']);
    expect(config.allowInsecureUrls).toBe(true);
    expect(config.deliveryTimeoutMs).toBe(2500);
  });

  it('lists every problem in one error', () => {
    expect(() => loadConfig({ ROLES: 'api,worker', PORT: '-1' })).toThrow(
      /DATABASE_URL[\s\S]*REDIS_URL[\s\S]*ROLES|PORT/,
    );
    expect(() => loadConfig({ ...required, ALLOW_PRIVATE_TARGETS: 'maybe' })).toThrow(/ALLOW_PRIVATE_TARGETS/);
  });
});
