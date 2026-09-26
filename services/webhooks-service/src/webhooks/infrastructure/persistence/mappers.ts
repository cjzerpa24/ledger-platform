import {
  Delivery as DeliveryRow,
  DeliveryAttempt as DeliveryAttemptRow,
  Prisma,
  Subscription as SubscriptionRow,
} from '../../../generated/prisma/client';
import { Delivery, DeliveryStatus } from '../../domain/delivery/delivery';
import { LedgerEventEnvelope } from '../../domain/event/ledger-event';
import { EventType } from '../../domain/subscription/event-type';
import { SigningSecret } from '../../domain/subscription/signing-secret';
import { Subscription, SubscriptionStatus } from '../../domain/subscription/subscription';
import { TargetUrl } from '../../domain/subscription/target-url';

export function toSubscription(row: SubscriptionRow): Subscription {
  return Subscription.restore({
    id: row.id,
    url: TargetUrl.fromPersistence(row.url),
    eventTypes: row.eventTypes as EventType[],
    secret: SigningSecret.fromPersistence(row.secret),
    status: row.status as SubscriptionStatus,
    description: row.description,
    createdAt: row.createdAt,
  });
}

export function fromSubscription(subscription: Subscription): SubscriptionRow {
  const s = subscription.snapshot();

  return {
    id: s.id,
    url: s.url.value,
    eventTypes: s.eventTypes,
    secret: s.secret.value,
    status: s.status,
    description: s.description,
    createdAt: s.createdAt,
  };
}

export function toDelivery(row: DeliveryRow & { attempts: DeliveryAttemptRow[] }): Delivery {
  return Delivery.restore({
    id: row.id,
    subscriptionId: row.subscriptionId,
    eventId: row.eventId,
    eventName: row.eventName,
    body: row.body as unknown as LedgerEventEnvelope,
    status: row.status as DeliveryStatus,
    attemptCount: row.attemptCount,
    nextAttemptAt: row.nextAttemptAt,
    lastError: row.lastError,
    createdAt: row.createdAt,
    attempts: [...row.attempts]
      .sort((a, b) => a.number - b.number)
      .map((a) => ({ number: a.number, at: a.at, statusCode: a.statusCode, error: a.error, durationMs: a.durationMs })),
  });
}

export function fromDelivery(delivery: Delivery): Prisma.DeliveryCreateManyInput {
  const d = delivery.snapshot();

  return {
    id: d.id,
    subscriptionId: d.subscriptionId,
    eventId: d.eventId,
    eventName: d.eventName,
    body: d.body as unknown as Prisma.InputJsonObject,
    status: d.status,
    attemptCount: d.attemptCount,
    nextAttemptAt: d.nextAttemptAt,
    lastError: d.lastError,
    createdAt: d.createdAt,
  };
}
