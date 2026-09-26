import { UnknownEventType } from './errors';

export const EVENT_TYPES = [
  'ledger.account_opened',
  'ledger.transfer_completed',
  'ledger.deposit_recorded',
  'ledger.withdrawal_recorded',
] as const;

export type EventType = (typeof EVENT_TYPES)[number];

export const isEventType = (value: string): value is EventType => (EVENT_TYPES as readonly string[]).includes(value);

/** Validates a subscription's event list; duplicates collapse to one entry. */
export function parseEventTypes(raw: readonly string[]): EventType[] {
  if (raw.length === 0) {
    throw new UnknownEventType('At least one event type is required.');
  }
  const unknown = raw.filter((name) => !isEventType(name));
  if (unknown.length > 0) {
    throw new UnknownEventType(
      `Unknown event type(s): ${unknown.join(', ')}. Expected any of: ${EVENT_TYPES.join(', ')}.`,
    );
  }

  return [...new Set(raw as readonly EventType[])];
}
