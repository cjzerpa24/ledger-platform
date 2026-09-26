import { Clock } from '../../src/webhooks/application/ports/clock';
import { HttpSender, OutgoingRequest, SendResult } from '../../src/webhooks/application/ports/http-sender';
import { SecretGenerator } from '../../src/webhooks/application/ports/secret-generator';
import { Delivery } from '../../src/webhooks/domain/delivery/delivery';
import { DeliveryPageQuery, DeliveryRepository } from '../../src/webhooks/domain/delivery/delivery-repository';
import { SigningSecret } from '../../src/webhooks/domain/subscription/signing-secret';
import { Subscription } from '../../src/webhooks/domain/subscription/subscription';
import { SubscriptionRepository } from '../../src/webhooks/domain/subscription/subscription-repository';
import { TEST_SECRET } from './fixtures';

export class FixedClock implements Clock {
  constructor(private current: Date = new Date('2026-09-25T10:00:00Z')) {}

  now(): Date {
    return new Date(this.current);
  }

  advance(ms: number): void {
    this.current = new Date(this.current.getTime() + ms);
  }
}

export class FixedSecretGenerator implements SecretGenerator {
  generate(): SigningSecret {
    return TEST_SECRET;
  }
}

export class FakeHttpSender implements HttpSender {
  readonly requests: OutgoingRequest[] = [];
  private results: SendResult[] = [];

  willRespond(...results: SendResult[]): void {
    this.results.push(...results);
  }

  async send(request: OutgoingRequest): Promise<SendResult> {
    this.requests.push(request);
    return this.results.shift() ?? { statusCode: 200, error: null, durationMs: 5 };
  }
}

export class InMemorySubscriptionRepository implements SubscriptionRepository {
  private readonly rows = new Map<string, Subscription>();

  async save(subscription: Subscription): Promise<void> {
    this.rows.set(subscription.id, Subscription.restore(subscription.snapshot()));
  }

  async findById(id: string): Promise<Subscription | null> {
    const row = this.rows.get(id);
    return row === undefined ? null : Subscription.restore(row.snapshot());
  }

  async list(): Promise<Subscription[]> {
    return [...this.rows.values()].map((row) => Subscription.restore(row.snapshot()));
  }

  async findActiveByEventType(eventName: string): Promise<Subscription[]> {
    return (await this.list()).filter((row) => row.matches(eventName));
  }
}

export class InMemoryDeliveryRepository implements DeliveryRepository {
  private readonly rows = new Map<string, Delivery>();

  constructor(private readonly subscriptions: InMemorySubscriptionRepository) {}

  async addIfAbsent(deliveries: readonly Delivery[]): Promise<number> {
    let inserted = 0;
    for (const delivery of deliveries) {
      const exists = [...this.rows.values()].some(
        (row) => row.subscriptionId === delivery.subscriptionId && row.eventId === delivery.eventId,
      );
      if (!exists) {
        this.rows.set(delivery.id, Delivery.restore(delivery.snapshot()));
        inserted += 1;
      }
    }
    return inserted;
  }

  async findById(id: string): Promise<Delivery | null> {
    const row = this.rows.get(id);
    return row === undefined ? null : Delivery.restore(row.snapshot());
  }

  async save(delivery: Delivery): Promise<void> {
    this.rows.set(delivery.id, Delivery.restore(delivery.snapshot()));
  }

  async claimDue(now: Date, limit: number, leaseUntil: Date): Promise<Delivery[]> {
    const due: Delivery[] = [];
    for (const row of this.sorted((a, b) => a.nextAttemptAt.getTime() - b.nextAttemptAt.getTime())) {
      const subscription = await this.subscriptions.findById(row.subscriptionId);
      if (row.status === 'pending' && row.nextAttemptAt <= now && subscription?.status === 'active') {
        due.push(row);
      }
    }
    return due.slice(0, limit).map((row) => {
      const leased = Delivery.restore({ ...row.snapshot(), nextAttemptAt: leaseUntil });
      this.rows.set(row.id, leased);
      return Delivery.restore(leased.snapshot());
    });
  }

  async listBySubscription(subscriptionId: string, query: DeliveryPageQuery): Promise<Delivery[]> {
    return this.sorted((a, b) => (a.id < b.id ? 1 : -1))
      .filter((row) => row.subscriptionId === subscriptionId)
      .filter((row) => query.status === undefined || row.status === query.status)
      .filter((row) => query.before === undefined || row.id < query.before)
      .slice(0, query.limit)
      .map((row) => Delivery.restore(row.snapshot()));
  }

  all(): Delivery[] {
    return [...this.rows.values()];
  }

  private sorted(compare: (a: Delivery, b: Delivery) => number): Delivery[] {
    return [...this.rows.values()].sort(compare);
  }
}
