import { LedgerEventEnvelope } from '../../src/webhooks/domain/event/ledger-event';

/** Exactly what Symfony Messenger's Redis transport writes (serializer=0, JSON Messenger serializer). */
export const messengerFrame = (envelope: LedgerEventEnvelope | Record<string, unknown>): string =>
  JSON.stringify({
    body: JSON.stringify(envelope),
    headers: { type: 'App\\Infrastructure\\Messaging\\IntegrationEvent', 'Content-Type': 'application/json' },
  });
