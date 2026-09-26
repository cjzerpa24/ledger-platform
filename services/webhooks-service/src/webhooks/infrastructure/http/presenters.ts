import { DeliveryPage } from '../../application/list-deliveries';
import { Delivery } from '../../domain/delivery/delivery';
import { Subscription } from '../../domain/subscription/subscription';

export function presentSubscription(subscription: Subscription, options: { withSecret?: boolean } = {}) {
  return {
    id: subscription.id,
    url: subscription.url.value,
    eventTypes: subscription.eventTypes,
    status: subscription.status,
    description: subscription.description,
    createdAt: subscription.createdAt.toISOString(),
    ...(options.withSecret === true ? { secret: subscription.secret.value } : {}),
  };
}

function presentDeliverySummary(delivery: Delivery) {
  const d = delivery.snapshot();

  return {
    id: d.id,
    subscriptionId: d.subscriptionId,
    eventId: d.eventId,
    eventName: d.eventName,
    status: d.status,
    attemptCount: d.attemptCount,
    nextAttemptAt: d.status === 'pending' ? d.nextAttemptAt.toISOString() : null,
    lastError: d.lastError,
    createdAt: d.createdAt.toISOString(),
  };
}

export function presentDelivery(delivery: Delivery) {
  return {
    ...presentDeliverySummary(delivery),
    body: delivery.body,
    attempts: delivery.attempts.map((a) => ({ ...a, at: a.at.toISOString() })),
  };
}

export function presentDeliveryPage(page: DeliveryPage) {
  return { items: page.items.map(presentDeliverySummary), nextCursor: page.nextCursor };
}
