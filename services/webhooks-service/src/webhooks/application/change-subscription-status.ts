import { Subscription } from '../domain/subscription/subscription';
import { SubscriptionRepository } from '../domain/subscription/subscription-repository';
import { GetSubscription } from './get-subscription';

abstract class ChangeSubscriptionStatus {
  private readonly get: GetSubscription;

  constructor(private readonly subscriptions: SubscriptionRepository) {
    this.get = new GetSubscription(subscriptions);
  }

  async execute(id: string): Promise<Subscription> {
    const subscription = await this.get.execute(id);
    this.apply(subscription);
    await this.subscriptions.save(subscription);

    return subscription;
  }

  protected abstract apply(subscription: Subscription): void;
}

export class PauseSubscription extends ChangeSubscriptionStatus {
  protected apply(subscription: Subscription): void {
    subscription.pause();
  }
}

export class ResumeSubscription extends ChangeSubscriptionStatus {
  protected apply(subscription: Subscription): void {
    subscription.resume();
  }
}
