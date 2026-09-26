import { Delivery } from '../../src/webhooks/domain/delivery/delivery';
import { RetryPolicy } from '../../src/webhooks/domain/delivery/retry-policy';
import { PrismaDeliveryRepository } from '../../src/webhooks/infrastructure/persistence/prisma-delivery-repository';
import { PrismaSubscriptionRepository } from '../../src/webhooks/infrastructure/persistence/prisma-subscription-repository';
import { PrismaService } from '../../src/webhooks/infrastructure/persistence/prisma.service';
import { resetDatabase, testConfig } from '../support/environment';
import { ledgerEvent, subscription } from '../support/fixtures';

const T0 = new Date('2026-09-25T10:00:00.000Z');
const at = (ms: number) => new Date(T0.getTime() + ms);

describe('Prisma repositories', () => {
  const prisma = new PrismaService(testConfig().databaseUrl);
  const otherPrisma = new PrismaService(testConfig().databaseUrl);
  const subscriptions = new PrismaSubscriptionRepository(prisma);
  const deliveries = new PrismaDeliveryRepository(prisma);

  beforeEach(() => resetDatabase(prisma));
  afterAll(async () => {
    await prisma.$disconnect();
    await otherPrisma.$disconnect();
  });

  it('round-trips a subscription and finds active ones by event type', async () => {
    const deposits = subscription({ eventTypes: ['ledger.deposit_recorded', 'ledger.account_opened'] });
    const paused = subscription({ eventTypes: ['ledger.deposit_recorded'] });
    paused.pause();
    await subscriptions.save(deposits);
    await subscriptions.save(paused);

    const loaded = await subscriptions.findById(deposits.id);
    expect(loaded?.snapshot()).toEqual(deposits.snapshot());
    expect((await subscriptions.findActiveByEventType('ledger.deposit_recorded')).map((s) => s.id)).toEqual([
      deposits.id,
    ]);
    expect(await subscriptions.list()).toHaveLength(2);
    expect(await subscriptions.findById('0199a000-0000-7000-8000-000000000000')).toBeNull();

    deposits.pause();
    await subscriptions.save(deposits);
    expect((await subscriptions.findById(deposits.id))?.status).toBe('paused');
  });

  it('inserts each (subscription, event) pair once', async () => {
    const sub = subscription();
    await subscriptions.save(sub);
    const event = ledgerEvent();

    expect(await deliveries.addIfAbsent([Delivery.create({ subscriptionId: sub.id, event, now: T0 })])).toBe(1);
    expect(await deliveries.addIfAbsent([Delivery.create({ subscriptionId: sub.id, event, now: T0 })])).toBe(0);
    expect(await deliveries.addIfAbsent([])).toBe(0);
    expect(await prisma.delivery.count()).toBe(1);
  });

  it('saves status changes and appends attempts without duplicating them', async () => {
    const sub = subscription();
    await subscriptions.save(sub);
    const delivery = Delivery.create({ subscriptionId: sub.id, event: ledgerEvent(), now: T0 });
    await deliveries.addIfAbsent([delivery]);

    delivery.recordFailure({ statusCode: 500, error: 'HTTP 500' }, 12, at(1000), RetryPolicy.DEFAULT);
    await deliveries.save(delivery);
    delivery.recordSuccess(200, 8, at(20_000));
    await deliveries.save(delivery);

    const loaded = await deliveries.findById(delivery.id);
    expect(loaded?.snapshot()).toEqual(delivery.snapshot());
    expect(loaded?.attempts.map((a) => [a.number, a.statusCode])).toEqual([
      [1, 500],
      [2, 200],
    ]);
  });

  it('claims only due, pending deliveries of active subscriptions and leases them', async () => {
    const active = subscription();
    const paused = subscription();
    paused.pause();
    await subscriptions.save(active);
    await subscriptions.save(paused);
    const due = Delivery.create({ subscriptionId: active.id, event: ledgerEvent(), now: T0 });
    const later = Delivery.create({ subscriptionId: active.id, event: ledgerEvent(), now: at(60_000) });
    const held = Delivery.create({ subscriptionId: paused.id, event: ledgerEvent(), now: T0 });
    const done = Delivery.create({ subscriptionId: active.id, event: ledgerEvent(), now: T0 });
    done.recordSuccess(200, 1, T0);
    await deliveries.addIfAbsent([due, later, held, done]);
    await deliveries.save(done);

    const claimed = await deliveries.claimDue(at(1000), 20, at(61_000));

    expect(claimed.map((d) => d.id)).toEqual([due.id]);
    expect(claimed[0]?.nextAttemptAt).toEqual(at(61_000));
    expect(await deliveries.claimDue(at(2000), 20, at(62_000))).toEqual([]);
    expect((await deliveries.claimDue(at(61_000), 20, at(121_000))).map((d) => d.id).sort()).toEqual(
      [due.id, later.id].sort(),
    );
  });

  it('never hands the same delivery to two concurrent dispatchers', async () => {
    const sub = subscription();
    await subscriptions.save(sub);
    await deliveries.addIfAbsent(
      Array.from({ length: 30 }, () => Delivery.create({ subscriptionId: sub.id, event: ledgerEvent(), now: T0 })),
    );
    const other = new PrismaDeliveryRepository(otherPrisma);

    const [a, b] = await Promise.all([
      deliveries.claimDue(at(1000), 20, at(61_000)),
      other.claimDue(at(1000), 20, at(61_000)),
    ]);

    const ids = [...a, ...b].map((d) => d.id);
    expect(new Set(ids).size).toBe(ids.length);
    expect(ids).toHaveLength(30);
  });

  it('lists deliveries newest first, filtered and paged', async () => {
    const sub = subscription();
    await subscriptions.save(sub);
    const created = Array.from({ length: 3 }, () =>
      Delivery.create({ subscriptionId: sub.id, event: ledgerEvent(), now: T0 }),
    );
    await deliveries.addIfAbsent(created);
    const newestFirst = created.map((d) => d.id).reverse();

    expect((await deliveries.listBySubscription(sub.id, { limit: 10 })).map((d) => d.id)).toEqual(newestFirst);
    expect(
      (await deliveries.listBySubscription(sub.id, { limit: 10, before: newestFirst[0] })).map((d) => d.id),
    ).toEqual(newestFirst.slice(1));
    expect(await deliveries.listBySubscription(sub.id, { limit: 10, status: 'dead' })).toEqual([]);
  });
});
