import { parseEventTypes } from '../domain/subscription/event-type';
import { Subscription } from '../domain/subscription/subscription';
import { SubscriptionRepository } from '../domain/subscription/subscription-repository';
import { TargetUrl } from '../domain/subscription/target-url';
import { Clock } from './ports/clock';
import { SecretGenerator } from './ports/secret-generator';

export interface RegisterSubscriptionInput {
  url: string;
  eventTypes: string[];
  description?: string | null;
}

export class RegisterSubscription {
  constructor(
    private readonly subscriptions: SubscriptionRepository,
    private readonly secrets: SecretGenerator,
    private readonly clock: Clock,
    private readonly urlPolicy: { allowInsecure: boolean; allowPrivate: boolean },
  ) {}

  async execute(input: RegisterSubscriptionInput): Promise<Subscription> {
    const subscription = Subscription.register({
      url: TargetUrl.create(input.url, this.urlPolicy),
      eventTypes: parseEventTypes(input.eventTypes),
      secret: this.secrets.generate(),
      description: input.description ?? null,
      now: this.clock.now(),
    });
    await this.subscriptions.save(subscription);

    return subscription;
  }
}
