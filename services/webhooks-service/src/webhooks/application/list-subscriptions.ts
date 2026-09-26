import { Subscription } from '../domain/subscription/subscription';
import { SubscriptionRepository } from '../domain/subscription/subscription-repository';

export class ListSubscriptions {
  constructor(private readonly subscriptions: SubscriptionRepository) {}

  execute(): Promise<Subscription[]> {
    return this.subscriptions.list();
  }
}
