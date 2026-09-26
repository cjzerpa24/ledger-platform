import { WebhooksError } from '../shared/webhooks-error';

export class InvalidTargetUrl extends WebhooksError {
  readonly code = 'invalid-target-url';
}

export class UnknownEventType extends WebhooksError {
  readonly code = 'unknown-event-type';
}

export class SubscriptionNotFound extends WebhooksError {
  readonly code = 'subscription-not-found';

  constructor(id: string) {
    super(`Subscription ${id} does not exist.`);
  }
}
