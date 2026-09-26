import { SubscriptionNotFound } from '../domain/subscription/errors';
import { Subscription } from '../domain/subscription/subscription';
import { SubscriptionRepository } from '../domain/subscription/subscription-repository';

export class GetSubscription {
  constructor(private readonly subscriptions: SubscriptionRepository) {}

  async execute(id: string): Promise<Subscription> {
    const subscription = await this.subscriptions.findById(id);
    if (subscription === null) {
      throw new SubscriptionNotFound(id);
    }

    return subscription;
  }
}
