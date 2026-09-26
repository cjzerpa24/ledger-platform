import { WebhooksError } from '../shared/webhooks-error';

export class DeliveryNotFound extends WebhooksError {
  readonly code = 'delivery-not-found';

  constructor(id: string) {
    super(`Delivery ${id} does not exist.`);
  }
}

export class DeliveryNotRetryable extends WebhooksError {
  readonly code = 'delivery-not-retryable';

  constructor(id: string, status: string) {
    super(`Delivery ${id} is ${status}; only dead deliveries can be retried.`);
  }
}
