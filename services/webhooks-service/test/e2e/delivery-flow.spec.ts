import { createHmac } from 'node:crypto';
import request from 'supertest';
import { LedgerEventEnvelope } from '../../src/webhooks/domain/event/ledger-event';
import { App } from 'supertest/types';
import { resetDatabase } from '../support/environment';
import { envelope } from '../support/fixtures';
import { messengerFrame } from '../support/messenger';
import { createTestApp, TestApp } from '../support/test-app';
import { WebhookReceiver } from '../support/webhook-receiver';

/** An independent Standard Webhooks verifier, as a subscriber would write it. */
function verify(secret: string, headers: Record<string, string | string[] | undefined>, body: string): boolean {
  const key = Buffer.from(secret.replace(/^whsec_/, ''), 'base64');
  const expected = createHmac('sha256', key)
    .update(`${String(headers['webhook-id'])}.${String(headers['webhook-timestamp'])}.${body}`)
    .digest('base64');

  return String(headers['webhook-signature'])
    .split(' ')
    .some((candidate) => candidate === `v1,${expected}`);
}

describe('ledger event → signed webhook', () => {
  let t: TestApp;
  let http: App;
  const receiver = new WebhookReceiver();

  beforeAll(async () => {
    await receiver.start();
    t = await createTestApp({
      ledgerStream: `ledger_events_e2e_${Date.now()}`,
      consumerName: 'e2e',
    });
    http = t.app.getHttpServer() as App;
    await t.consumer.ensureGroup();
  });
  beforeEach(async () => {
    await resetDatabase(t.prisma);
    receiver.requests.length = 0;
    receiver.respondWith(204);
  });
  afterAll(async () => {
    await t.redis.del(t.config.ledgerStream);
    await t.app.close();
    await receiver.stop();
  });

  const subscribe = async (eventTypes: string[]) =>
    (
      await request(http)
        .post('/subscriptions')
        .send({ url: receiver.url('/ledger'), eventTypes })
        .expect(201)
    ).body as { id: string; secret: string };
  const publish = (raw: LedgerEventEnvelope) =>
    t.redis.xadd(t.config.ledgerStream, '*', 'message', messengerFrame(raw));

  it('delivers a matching event, signed, and records success', async () => {
    const sub = await subscribe(['ledger.transfer_completed']);
    await subscribe(['ledger.account_opened']);
    const raw = envelope();

    await publish(raw);
    expect(await t.consumer.processBatch('>')).toBe(1);
    expect(await t.dispatch.execute()).toBe(1);

    expect(receiver.requests).toHaveLength(1);
    const [received] = receiver.requests;
    expect(received?.path).toBe('/ledger');
    expect(JSON.parse(received!.body)).toEqual(raw);
    expect(received?.headers['webhook-id']).toBe(raw.eventId);
    expect(received?.headers['user-agent']).toBe('ledger-platform-webhooks/1');
    expect(verify(sub.secret, received!.headers, received!.body)).toBe(true);
    expect(verify(`whsec_${Buffer.alloc(32).toString('base64')}`, received!.headers, received!.body)).toBe(false);

    const log = await request(http).get(`/subscriptions/${sub.id}/deliveries`).expect(200);
    expect(log.body.items).toMatchObject([{ eventId: raw.eventId, status: 'succeeded', attemptCount: 1 }]);
  });

  it('schedules a retry when the subscriber fails', async () => {
    const sub = await subscribe(['ledger.transfer_completed']);
    receiver.respondWith(503);

    await publish(envelope());
    await t.consumer.processBatch('>');
    const before = Date.now();
    await t.dispatch.execute();

    const log = await request(http).get(`/subscriptions/${sub.id}/deliveries`).expect(200);
    const [item] = log.body.items;
    expect(item).toMatchObject({ status: 'pending', attemptCount: 1, lastError: 'HTTP 503' });
    const due = Date.parse(item.nextAttemptAt);
    expect(due).toBeGreaterThanOrEqual(before + 10_000 - 1000);
    expect(due).toBeLessThanOrEqual(Date.now() + 10_000 + 1000);
    expect(await t.dispatch.execute()).toBe(0);

    const detail = await request(http).get(`/deliveries/${item.id}`).expect(200);
    expect(detail.body.attempts).toMatchObject([{ number: 1, statusCode: 503, error: 'HTTP 503' }]);
  });

  it('turns a duplicated stream message into one delivery', async () => {
    await subscribe(['ledger.transfer_completed']);
    const raw = envelope();

    await publish(raw);
    await publish(raw);
    expect(await t.consumer.processBatch('>')).toBe(2);
    await t.dispatch.execute();

    expect(receiver.requests).toHaveLength(1);
    expect(await t.prisma.delivery.count()).toBe(1);
  });

  it('holds events for a paused subscription and sends them on resume', async () => {
    const sub = await subscribe(['ledger.transfer_completed']);
    await publish(envelope());
    await t.consumer.processBatch('>');
    await request(http).post(`/subscriptions/${sub.id}/pause`).expect(200);

    expect(await t.dispatch.execute()).toBe(0);
    await request(http).post(`/subscriptions/${sub.id}/resume`).expect(200);
    expect(await t.dispatch.execute()).toBe(1);
    expect(receiver.requests).toHaveLength(1);
  });

  it('ignores events of other or unknown types', async () => {
    const sub = await subscribe(['ledger.deposit_recorded']);
    await publish(envelope({ eventName: 'ledger.withdrawal_recorded' }));
    await publish(envelope({ eventName: 'ledger.something_new' }));
    await t.consumer.processBatch('>');

    expect(await t.dispatch.execute()).toBe(0);
    const log = await request(http).get(`/subscriptions/${sub.id}/deliveries`).expect(200);
    expect(log.body.items).toEqual([]);
  });
});
