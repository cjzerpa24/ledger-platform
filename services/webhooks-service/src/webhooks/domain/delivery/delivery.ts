import { LedgerEvent, LedgerEventEnvelope } from '../event/ledger-event';
import { newId } from '../shared/id';
import { DeliveryNotRetryable } from './errors';
import { RetryPolicy } from './retry-policy';

export type DeliveryStatus = 'pending' | 'succeeded' | 'dead';

export const DELIVERY_STATUSES: readonly DeliveryStatus[] = ['pending', 'succeeded', 'dead'];

const MAX_ERROR_LENGTH = 500;

export interface DeliveryAttempt {
  number: number;
  at: Date;
  statusCode: number | null;
  error: string | null;
  durationMs: number;
}

export interface DeliverySnapshot {
  id: string;
  subscriptionId: string;
  eventId: string;
  eventName: string;
  body: LedgerEventEnvelope;
  status: DeliveryStatus;
  attemptCount: number;
  nextAttemptAt: Date;
  lastError: string | null;
  createdAt: Date;
  attempts: DeliveryAttempt[];
}

/** One ledger event on its way to one subscription. */
export class Delivery {
  private constructor(private state: DeliverySnapshot) {}

  static create(input: { subscriptionId: string; event: LedgerEvent; now: Date }): Delivery {
    return new Delivery({
      id: newId(),
      subscriptionId: input.subscriptionId,
      eventId: input.event.eventId,
      eventName: input.event.eventName,
      body: input.event.envelope,
      status: 'pending',
      attemptCount: 0,
      nextAttemptAt: input.now,
      lastError: null,
      createdAt: input.now,
      attempts: [],
    });
  }

  static restore(snapshot: DeliverySnapshot): Delivery {
    return new Delivery({ ...snapshot, attempts: [...snapshot.attempts] });
  }

  get id(): string {
    return this.state.id;
  }

  get subscriptionId(): string {
    return this.state.subscriptionId;
  }

  get eventId(): string {
    return this.state.eventId;
  }

  get body(): LedgerEventEnvelope {
    return this.state.body;
  }

  get status(): DeliveryStatus {
    return this.state.status;
  }

  get attemptCount(): number {
    return this.state.attemptCount;
  }

  get nextAttemptAt(): Date {
    return this.state.nextAttemptAt;
  }

  get lastError(): string | null {
    return this.state.lastError;
  }

  get attempts(): readonly DeliveryAttempt[] {
    return this.state.attempts;
  }

  recordSuccess(statusCode: number, durationMs: number, now: Date): void {
    this.appendAttempt({ statusCode, error: null, durationMs, at: now });
    this.state.status = 'succeeded';
    this.state.lastError = null;
  }

  recordFailure(
    failure: { statusCode: number | null; error: string },
    durationMs: number,
    now: Date,
    policy: RetryPolicy,
  ): void {
    const error = failure.error.slice(0, MAX_ERROR_LENGTH);
    this.appendAttempt({ statusCode: failure.statusCode, error, durationMs, at: now });
    this.state.lastError = error;
    if (this.state.attemptCount >= policy.maxAttempts) {
      this.state.status = 'dead';
      return;
    }
    this.state.nextAttemptAt = new Date(now.getTime() + policy.delayAfter(this.state.attemptCount));
  }

  /** Sends a dead delivery again with a fresh attempt budget; the attempt history is kept. */
  retry(now: Date): void {
    if (this.state.status !== 'dead') {
      throw new DeliveryNotRetryable(this.state.id, this.state.status);
    }
    this.state.status = 'pending';
    this.state.attemptCount = 0;
    this.state.nextAttemptAt = now;
  }

  snapshot(): DeliverySnapshot {
    return { ...this.state, attempts: [...this.state.attempts] };
  }

  private appendAttempt(attempt: Omit<DeliveryAttempt, 'number'>): void {
    this.state.attemptCount += 1;
    this.state.attempts.push({ number: this.state.attempts.length + 1, ...attempt });
  }
}
