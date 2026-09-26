import { newId } from '../shared/id';
import { EventType } from './event-type';
import { SigningSecret } from './signing-secret';
import { TargetUrl } from './target-url';

export type SubscriptionStatus = 'active' | 'paused';

export interface SubscriptionSnapshot {
  id: string;
  url: TargetUrl;
  eventTypes: EventType[];
  secret: SigningSecret;
  status: SubscriptionStatus;
  description: string | null;
  createdAt: Date;
}

export class Subscription {
  private constructor(private state: SubscriptionSnapshot) {}

  static register(input: {
    url: TargetUrl;
    eventTypes: EventType[];
    secret: SigningSecret;
    description: string | null;
    now: Date;
  }): Subscription {
    return new Subscription({
      id: newId(),
      url: input.url,
      eventTypes: [...input.eventTypes],
      secret: input.secret,
      status: 'active',
      description: input.description,
      createdAt: input.now,
    });
  }

  static restore(snapshot: SubscriptionSnapshot): Subscription {
    return new Subscription({ ...snapshot, eventTypes: [...snapshot.eventTypes] });
  }

  get id(): string {
    return this.state.id;
  }

  get url(): TargetUrl {
    return this.state.url;
  }

  get eventTypes(): readonly EventType[] {
    return this.state.eventTypes;
  }

  get secret(): SigningSecret {
    return this.state.secret;
  }

  get status(): SubscriptionStatus {
    return this.state.status;
  }

  get description(): string | null {
    return this.state.description;
  }

  get createdAt(): Date {
    return this.state.createdAt;
  }

  pause(): void {
    this.state.status = 'paused';
  }

  resume(): void {
    this.state.status = 'active';
  }

  matches(eventName: string): boolean {
    return this.state.status === 'active' && (this.state.eventTypes as readonly string[]).includes(eventName);
  }

  snapshot(): SubscriptionSnapshot {
    return { ...this.state, eventTypes: [...this.state.eventTypes] };
  }
}
