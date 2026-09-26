import { LedgerEvent, LedgerEventEnvelope } from '../../src/webhooks/domain/event/ledger-event';
import { EventType } from '../../src/webhooks/domain/subscription/event-type';
import { SigningSecret } from '../../src/webhooks/domain/subscription/signing-secret';
import { Subscription } from '../../src/webhooks/domain/subscription/subscription';
import { TargetUrl } from '../../src/webhooks/domain/subscription/target-url';
import { v7 } from 'uuid';

export const TEST_SECRET = SigningSecret.fromKey(Buffer.alloc(32, 7));

export function envelope(overrides: Partial<LedgerEventEnvelope> = {}): LedgerEventEnvelope {
  return {
    eventId: v7(),
    eventName: 'ledger.transfer_completed',
    eventVersion: 1,
    occurredAt: '2026-09-25T10:00:00.000+00:00',
    aggregateId: v7(),
    payload: {
      transferId: v7(),
      journalEntryId: v7(),
      sourceAccountId: v7(),
      destinationAccountId: v7(),
      amount: '10.00',
      amountMinor: 1000,
      currency: 'USD',
      description: 'Coffee',
      completedAt: '2026-09-25T10:00:00.000+00:00',
    },
    ...overrides,
  };
}

export const ledgerEvent = (overrides: Partial<LedgerEventEnvelope> = {}): LedgerEvent =>
  LedgerEvent.parse(envelope(overrides));

export function subscription(
  options: { url?: string; eventTypes?: EventType[]; now?: Date; secret?: SigningSecret } = {},
): Subscription {
  return Subscription.register({
    url: TargetUrl.create(options.url ?? 'https://example.test/hooks', { allowInsecure: true, allowPrivate: true }),
    eventTypes: options.eventTypes ?? ['ledger.transfer_completed'],
    secret: options.secret ?? TEST_SECRET,
    description: null,
    now: options.now ?? new Date('2026-09-25T10:00:00Z'),
  });
}
