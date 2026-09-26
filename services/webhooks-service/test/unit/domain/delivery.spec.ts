import { Delivery } from '../../../src/webhooks/domain/delivery/delivery';
import { DeliveryNotRetryable } from '../../../src/webhooks/domain/delivery/errors';
import { RetryPolicy } from '../../../src/webhooks/domain/delivery/retry-policy';
import { ledgerEvent } from '../../support/fixtures';

const T0 = new Date('2026-09-25T10:00:00Z');
const at = (ms: number) => new Date(T0.getTime() + ms);
const newDelivery = () => Delivery.create({ subscriptionId: 'sub-1', event: ledgerEvent(), now: T0 });

describe('RetryPolicy.DEFAULT', () => {
  it('waits 10s, 30s, 2m, 10m, 30m, 1h, 3h and allows 8 attempts', () => {
    const policy = RetryPolicy.DEFAULT;

    expect([1, 2, 3, 4, 5, 6, 7].map((n) => policy.delayAfter(n) / 1000)).toEqual([
      10, 30, 120, 600, 1800, 3600, 10800,
    ]);
    expect(policy.maxAttempts).toBe(8);
  });
});

describe('Delivery', () => {
  it('starts pending and due immediately', () => {
    const delivery = newDelivery();

    expect(delivery.status).toBe('pending');
    expect(delivery.attemptCount).toBe(0);
    expect(delivery.nextAttemptAt).toEqual(T0);
  });

  it('records a success', () => {
    const delivery = newDelivery();
    delivery.recordSuccess(204, 12, at(1000));

    expect(delivery.status).toBe('succeeded');
    expect(delivery.attempts).toEqual([{ number: 1, at: at(1000), statusCode: 204, error: null, durationMs: 12 }]);
  });

  it('schedules the next attempt from the policy after a failure', () => {
    const delivery = newDelivery();
    delivery.recordFailure({ statusCode: 500, error: 'HTTP 500' }, 30, at(0), RetryPolicy.DEFAULT);

    expect(delivery.status).toBe('pending');
    expect(delivery.attemptCount).toBe(1);
    expect(delivery.lastError).toBe('HTTP 500');
    expect(delivery.nextAttemptAt).toEqual(at(10_000));
  });

  it('dies after the eighth failure', () => {
    const delivery = newDelivery();
    for (let i = 0; i < 7; i += 1) {
      delivery.recordFailure({ statusCode: null, error: 'timeout' }, 10, at(0), RetryPolicy.DEFAULT);
      expect(delivery.status).toBe('pending');
    }
    delivery.recordFailure({ statusCode: null, error: 'timeout' }, 10, at(0), RetryPolicy.DEFAULT);

    expect(delivery.status).toBe('dead');
    expect(delivery.attempts).toHaveLength(8);
  });

  it('truncates long errors to 500 characters', () => {
    const delivery = newDelivery();
    delivery.recordFailure({ statusCode: null, error: 'x'.repeat(2000) }, 1, at(0), RetryPolicy.DEFAULT);

    expect(delivery.lastError).toHaveLength(500);
    expect(delivery.attempts[0]?.error).toHaveLength(500);
  });

  it('retries a dead delivery with a fresh budget and keeps the history', () => {
    const policy = new RetryPolicy([]);
    const delivery = newDelivery();
    delivery.recordFailure({ statusCode: 500, error: 'HTTP 500' }, 1, at(0), policy);
    expect(delivery.status).toBe('dead');

    delivery.retry(at(5000));

    expect(delivery.status).toBe('pending');
    expect(delivery.attemptCount).toBe(0);
    expect(delivery.nextAttemptAt).toEqual(at(5000));
    expect(delivery.attempts).toHaveLength(1);

    delivery.recordSuccess(200, 1, at(6000));
    expect(delivery.attempts.map((a) => a.number)).toEqual([1, 2]);
  });

  it.each(['pending', 'succeeded'])('refuses to retry a %s delivery', (status) => {
    const delivery = newDelivery();
    if (status === 'succeeded') {
      delivery.recordSuccess(200, 1, at(0));
    }

    expect(() => delivery.retry(at(0))).toThrow(DeliveryNotRetryable);
  });
});
