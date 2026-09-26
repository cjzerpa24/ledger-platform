import { Delivery, DeliveryStatus } from './delivery';

export const DELIVERY_REPOSITORY = Symbol('DeliveryRepository');

export interface DeliveryPageQuery {
  status?: DeliveryStatus;
  /** Maximum number of rows to return. */
  limit: number;
  /** Return deliveries older than this delivery id. */
  before?: string;
}

export interface DeliveryRepository {
  /** Inserts the deliveries, skipping any (subscriptionId, eventId) pair that already exists. Returns how many were inserted. */
  addIfAbsent(deliveries: readonly Delivery[]): Promise<number>;
  findById(id: string): Promise<Delivery | null>;
  /** Persists status fields and any attempts not stored yet. */
  save(delivery: Delivery): Promise<void>;
  /**
   * Atomically claims up to `limit` pending deliveries that are due at `now` and belong to an active
   * subscription, pushing their nextAttemptAt to `leaseUntil` so no other dispatcher claims them.
   */
  claimDue(now: Date, limit: number, leaseUntil: Date): Promise<Delivery[]>;
  /** Newest first. */
  listBySubscription(subscriptionId: string, query: DeliveryPageQuery): Promise<Delivery[]>;
}
