import request from 'supertest';
import { App } from 'supertest/types';
import { v7 } from 'uuid';
import { Delivery } from '../../src/webhooks/domain/delivery/delivery';
import { RetryPolicy } from '../../src/webhooks/domain/delivery/retry-policy';
import { PrismaDeliveryRepository } from '../../src/webhooks/infrastructure/persistence/prisma-delivery-repository';
import { resetDatabase } from '../support/environment';
import { ledgerEvent } from '../support/fixtures';
import { createTestApp, TestApp } from '../support/test-app';

const PROBLEM = 'application/problem+json';
const MISSING = '0199a000-0000-7000-8000-000000000000';

describe('HTTP API', () => {
  let t: TestApp;
  let http: App;

  const create = (body: object) => request(http).post('/subscriptions').send(body);
  const createValid = async (eventTypes = ['ledger.deposit_recorded']) =>
    (await create({ url: 'https://example.test/hook', eventTypes }).expect(201)).body as { id: string };
  const expectProblem = (res: request.Response, status: number, type: string) => {
    expect(res.status).toBe(status);
    expect(res.headers['content-type']).toContain(PROBLEM);
    expect(res.body).toMatchObject({ type: `urn:webhooks:problem:${type}`, status });
  };

  beforeAll(async () => {
    t = await createTestApp({ allowInsecureUrls: false, allowPrivateTargets: false });
    http = t.app.getHttpServer() as App;
  });
  beforeEach(() => resetDatabase(t.prisma));
  afterAll(() => t.app.close());

  describe('POST /subscriptions', () => {
    it('creates a subscription and returns the secret once', async () => {
      const res = await create({
        url: 'https://example.test/hook',
        eventTypes: ['ledger.deposit_recorded', 'ledger.deposit_recorded'],
        description: 'Accounting',
      }).expect(201);

      expect(res.body).toMatchObject({
        url: 'https://example.test/hook',
        eventTypes: ['ledger.deposit_recorded'],
        status: 'active',
        description: 'Accounting',
      });
      expect(res.body.secret).toMatch(/^whsec_[A-Za-z0-9+/]{43}=$/);

      const shown = await request(http).get(`/subscriptions/${res.body.id}`).expect(200);
      expect(shown.body.secret).toBeUndefined();
      expect(shown.body.id).toBe(res.body.id);
    });

    it.each<[string, object]>([
      ['a missing url', { eventTypes: ['ledger.deposit_recorded'] }],
      ['missing eventTypes', { url: 'https://example.test' }],
      ['empty eventTypes', { url: 'https://example.test', eventTypes: [] }],
      ['non-string eventTypes', { url: 'https://example.test', eventTypes: [1] }],
      ['an unknown field', { url: 'https://example.test', eventTypes: ['ledger.deposit_recorded'], admin: true }],
      [
        'a too long description',
        { url: 'https://example.test', eventTypes: ['ledger.deposit_recorded'], description: 'x'.repeat(256) },
      ],
    ])('rejects %s with validation-failed', async (_label, body) => {
      const res = await create(body);

      expectProblem(res, 422, 'validation-failed');
      expect(Array.isArray(res.body.errors)).toBe(true);
      expect(res.body.errors.length).toBeGreaterThan(0);
    });

    it.each(['http://example.test/hook', 'not-a-url', 'https://127.0.0.1/hook', 'https://localhost/hook'])(
      'rejects the URL %s',
      async (url) => {
        expectProblem(await create({ url, eventTypes: ['ledger.deposit_recorded'] }), 422, 'invalid-target-url');
      },
    );

    it('rejects unknown event types', async () => {
      expectProblem(
        await create({ url: 'https://example.test', eventTypes: ['ledger.bogus'] }),
        422,
        'unknown-event-type',
      );
    });

    it('rejects a malformed JSON body with 400, not 500', async () => {
      const res = await request(http).post('/subscriptions').set('content-type', 'application/json').send('{"url":');

      expectProblem(res, 400, 'malformed-request');
    });
  });

  describe('reading and changing subscriptions', () => {
    it('lists subscriptions without secrets', async () => {
      await createValid();
      await createValid();

      const res = await request(http).get('/subscriptions').expect(200);
      expect(res.body.items).toHaveLength(2);
      expect(res.body.items.every((s: { secret?: string }) => s.secret === undefined)).toBe(true);
    });

    it('pauses and resumes', async () => {
      const { id } = await createValid();

      expect((await request(http).post(`/subscriptions/${id}/pause`).expect(200)).body.status).toBe('paused');
      expect((await request(http).post(`/subscriptions/${id}/pause`).expect(200)).body.status).toBe('paused');
      expect((await request(http).post(`/subscriptions/${id}/resume`).expect(200)).body.status).toBe('active');
    });

    it('returns 404 for an unknown subscription and 422 for a malformed id', async () => {
      expectProblem(await request(http).get(`/subscriptions/${MISSING}`), 404, 'subscription-not-found');
      expectProblem(await request(http).post(`/subscriptions/${MISSING}/pause`), 404, 'subscription-not-found');
      expectProblem(await request(http).get(`/subscriptions/${MISSING}/deliveries`), 404, 'subscription-not-found');
      expectProblem(await request(http).get('/subscriptions/not-a-uuid'), 422, 'validation-failed');
    });

    it('returns problem+json 404 for unknown routes', async () => {
      expectProblem(await request(http).get('/nope'), 404, 'not-found');
    });
  });

  describe('deliveries', () => {
    const seed = async (subscriptionId: string, count: number) => {
      const repo = new PrismaDeliveryRepository(t.prisma);
      const rows = Array.from({ length: count }, () =>
        Delivery.create({
          subscriptionId,
          event: ledgerEvent({ eventName: 'ledger.deposit_recorded' }),
          now: new Date(),
        }),
      );
      await repo.addIfAbsent(rows);
      return rows;
    };

    it('pages the delivery log newest first', async () => {
      const { id } = await createValid();
      const rows = await seed(id, 3);
      const newestFirst = rows.map((r) => r.id).reverse();

      const first = await request(http).get(`/subscriptions/${id}/deliveries?limit=2`).expect(200);
      expect(first.body.items.map((d: { id: string }) => d.id)).toEqual(newestFirst.slice(0, 2));
      expect(first.body.nextCursor).toBe(newestFirst[1]);

      const second = await request(http)
        .get(`/subscriptions/${id}/deliveries?limit=2&cursor=${first.body.nextCursor}`)
        .expect(200);
      expect(second.body.items.map((d: { id: string }) => d.id)).toEqual(newestFirst.slice(2));
      expect(second.body.nextCursor).toBeNull();

      const filtered = await request(http).get(`/subscriptions/${id}/deliveries?status=dead`).expect(200);
      expect(filtered.body.items).toEqual([]);
    });

    it.each(['limit=0', 'limit=101', 'limit=abc', 'status=lost', 'cursor=nope'])(
      'rejects the query %s',
      async (query) => {
        const { id } = await createValid();
        expectProblem(await request(http).get(`/subscriptions/${id}/deliveries?${query}`), 422, 'validation-failed');
      },
    );

    it('shows a delivery with its body and attempts, and retries it only when dead', async () => {
      const { id } = await createValid();
      const [row] = await seed(id, 1);
      const deliveryId = row!.id;

      const shown = await request(http).get(`/deliveries/${deliveryId}`).expect(200);
      expect(shown.body).toMatchObject({ id: deliveryId, status: 'pending', attempts: [] });
      expect(shown.body.body.eventId).toBe(row!.eventId);

      expectProblem(await request(http).post(`/deliveries/${deliveryId}/retry`), 409, 'delivery-not-retryable');

      const repo = new PrismaDeliveryRepository(t.prisma);
      const dead = (await repo.findById(deliveryId))!;
      dead.recordFailure({ statusCode: 500, error: 'HTTP 500' }, 3, new Date(), new RetryPolicy([]));
      await repo.save(dead);

      const retried = await request(http).post(`/deliveries/${deliveryId}/retry`).expect(200);
      expect(retried.body).toMatchObject({ status: 'pending', attemptCount: 0 });
      expect(retried.body.attempts).toHaveLength(1);

      expectProblem(await request(http).get(`/deliveries/${v7()}`), 404, 'delivery-not-found');
      expectProblem(await request(http).post('/deliveries/xyz/retry'), 422, 'validation-failed');
    });
  });

  it('GET /health reports database and redis', async () => {
    const res = await request(http).get('/health').expect(200);

    expect(res.body).toEqual({ status: 'ok', checks: { database: 'up', redis: 'up' } });
  });
});
