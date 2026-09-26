import { DispatchDueDeliveries } from '../../../src/webhooks/application/dispatch-due-deliveries';
import { GetDelivery } from '../../../src/webhooks/application/get-delivery';
import { IngestLedgerEvent } from '../../../src/webhooks/application/ingest-ledger-event';
import { ListDeliveries } from '../../../src/webhooks/application/list-deliveries';
import { Signer } from '../../../src/webhooks/application/ports/signer';
import { RetryDelivery } from '../../../src/webhooks/application/retry-delivery';
import { DeliveryNotFound, DeliveryNotRetryable } from '../../../src/webhooks/domain/delivery/errors';
import { RetryPolicy } from '../../../src/webhooks/domain/delivery/retry-policy';
import { SubscriptionNotFound } from '../../../src/webhooks/domain/subscription/errors';
import { ledgerEvent, subscription } from '../../support/fixtures';
import {
  FakeHttpSender,
  FixedClock,
  InMemoryDeliveryRepository,
  InMemorySubscriptionRepository,
} from '../../support/fakes';

const signer: Signer = { sign: ({ id, timestamp }) => `v1,sig-${id}-${timestamp}` };

describe('delivery use cases', () => {
  let clock: FixedClock;
  let subscriptions: InMemorySubscriptionRepository;
  let deliveries: InMemoryDeliveryRepository;
  let sender: FakeHttpSender;
  let ingest: IngestLedgerEvent;
  let dispatch: DispatchDueDeliveries;

  beforeEach(() => {
    clock = new FixedClock();
    subscriptions = new InMemorySubscriptionRepository();
    deliveries = new InMemoryDeliveryRepository(subscriptions);
    sender = new FakeHttpSender();
    ingest = new IngestLedgerEvent(subscriptions, deliveries, clock);
    dispatch = new DispatchDueDeliveries(deliveries, subscriptions, sender, signer, clock, {
      batchSize: 20,
      leaseMs: 60_000,
      timeoutMs: 10_000,
      policy: RetryPolicy.DEFAULT,
    });
  });

  describe('IngestLedgerEvent', () => {
    it('creates one delivery per matching active subscription', async () => {
      const deposits = subscription({ eventTypes: ['ledger.deposit_recorded'] });
      const both = subscription({ eventTypes: ['ledger.deposit_recorded', 'ledger.transfer_completed'] });
      const paused = subscription({ eventTypes: ['ledger.deposit_recorded'] });
      paused.pause();
      const other = subscription({ eventTypes: ['ledger.account_opened'] });
      for (const s of [deposits, both, paused, other]) {
        await subscriptions.save(s);
      }

      const created = await ingest.execute(ledgerEvent({ eventName: 'ledger.deposit_recorded' }));

      expect(created).toBe(2);
      expect(
        deliveries
          .all()
          .map((d) => d.subscriptionId)
          .sort(),
      ).toEqual([deposits.id, both.id].sort());
    });

    it('ignores the same event a second time', async () => {
      await subscriptions.save(subscription());
      const event = ledgerEvent();

      expect(await ingest.execute(event)).toBe(1);
      expect(await ingest.execute(event)).toBe(0);
      expect(deliveries.all()).toHaveLength(1);
    });

    it('creates nothing for an event nobody subscribes to', async () => {
      await subscriptions.save(subscription());

      expect(await ingest.execute(ledgerEvent({ eventName: 'ledger.something_new' }))).toBe(0);
    });
  });

  describe('DispatchDueDeliveries', () => {
    it('sends the envelope signed with Standard Webhooks headers and records success', async () => {
      const sub = subscription({ url: 'https://receiver.test/hook' });
      await subscriptions.save(sub);
      const event = ledgerEvent();
      await ingest.execute(event);

      expect(await dispatch.execute()).toBe(1);

      const [request] = sender.requests;
      const timestamp = Math.floor(clock.now().getTime() / 1000);
      expect(request?.url).toBe('https://receiver.test/hook');
      expect(JSON.parse(request?.body ?? '')).toEqual(event.envelope);
      expect(request?.headers).toMatchObject({
        'content-type': 'application/json',
        'webhook-id': event.eventId,
        'webhook-timestamp': String(timestamp),
        'webhook-signature': `v1,sig-${event.eventId}-${timestamp}`,
      });
      expect(request?.timeoutMs).toBe(10_000);
      expect(deliveries.all()[0]?.status).toBe('succeeded');
    });

    it.each([
      [{ statusCode: 500, error: null, durationMs: 3 }, 'HTTP 500'],
      [{ statusCode: 302, error: null, durationMs: 3 }, 'HTTP 302'],
      [{ statusCode: null, error: 'timeout after 10000ms', durationMs: 10_000 }, 'timeout after 10000ms'],
    ])('treats %p as a failure and schedules a retry', async (result, error) => {
      await subscriptions.save(subscription());
      await ingest.execute(ledgerEvent());
      sender.willRespond(result);

      await dispatch.execute();

      const [delivery] = deliveries.all();
      expect(delivery?.status).toBe('pending');
      expect(delivery?.lastError).toBe(error);
      expect(delivery?.nextAttemptAt).toEqual(new Date(clock.now().getTime() + 10_000));
    });

    it('does not claim a delivery again until its retry is due', async () => {
      await subscriptions.save(subscription());
      await ingest.execute(ledgerEvent());
      sender.willRespond({ statusCode: 503, error: null, durationMs: 1 });

      await dispatch.execute();
      expect(await dispatch.execute()).toBe(0);

      clock.advance(10_000);
      expect(await dispatch.execute()).toBe(1);
      expect(deliveries.all()[0]?.status).toBe('succeeded');
    });

    it('holds deliveries of a paused subscription until it resumes', async () => {
      const sub = subscription();
      await subscriptions.save(sub);
      await ingest.execute(ledgerEvent());
      sub.pause();
      await subscriptions.save(sub);

      expect(await dispatch.execute()).toBe(0);

      sub.resume();
      await subscriptions.save(sub);
      expect(await dispatch.execute()).toBe(1);
    });
  });

  describe('queries and retry', () => {
    it('pages deliveries newest first with a cursor', async () => {
      const sub = subscription();
      await subscriptions.save(sub);
      for (let i = 0; i < 3; i += 1) {
        await ingest.execute(ledgerEvent());
      }
      const list = new ListDeliveries(subscriptions, deliveries);

      const first = await list.execute({ subscriptionId: sub.id, limit: 2 });
      expect(first.items).toHaveLength(2);
      expect(first.nextCursor).toBe(first.items[1]?.id);

      const second = await list.execute({ subscriptionId: sub.id, limit: 2, cursor: first.nextCursor ?? undefined });
      expect(second.items).toHaveLength(1);
      expect(second.nextCursor).toBeNull();
      expect(new Set([...first.items, ...second.items].map((d) => d.id)).size).toBe(3);
    });

    it('filters by status and rejects an unknown subscription', async () => {
      const sub = subscription();
      await subscriptions.save(sub);
      await ingest.execute(ledgerEvent());
      const list = new ListDeliveries(subscriptions, deliveries);

      expect((await list.execute({ subscriptionId: sub.id, limit: 20, status: 'dead' })).items).toEqual([]);
      await expect(list.execute({ subscriptionId: '0199a000-0000-7000-8000-000000000000', limit: 20 })).rejects.toThrow(
        SubscriptionNotFound,
      );
    });

    it('retries only dead deliveries', async () => {
      await subscriptions.save(subscription());
      await ingest.execute(ledgerEvent());
      const [delivery] = deliveries.all();
      const retry = new RetryDelivery(deliveries, clock);

      await expect(retry.execute(delivery!.id)).rejects.toThrow(DeliveryNotRetryable);

      const dead = await new GetDelivery(deliveries).execute(delivery!.id);
      dead.recordFailure({ statusCode: 500, error: 'HTTP 500' }, 1, clock.now(), new RetryPolicy([]));
      await deliveries.save(dead);

      expect((await retry.execute(delivery!.id)).status).toBe('pending');
      await expect(new GetDelivery(deliveries).execute('0199a000-0000-7000-8000-000000000000')).rejects.toThrow(
        DeliveryNotFound,
      );
    });
  });
});
