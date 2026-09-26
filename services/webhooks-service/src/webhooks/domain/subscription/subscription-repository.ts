import { Subscription } from './subscription';

export const SUBSCRIPTION_REPOSITORY = Symbol('SubscriptionRepository');

export interface SubscriptionRepository {
  save(subscription: Subscription): Promise<void>;
  findById(id: string): Promise<Subscription | null>;
  /** Oldest first. */
  list(): Promise<Subscription[]>;
  findActiveByEventType(eventName: string): Promise<Subscription[]>;
}
