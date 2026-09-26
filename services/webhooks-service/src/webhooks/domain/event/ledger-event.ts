import { WebhooksError } from '../shared/webhooks-error';

export class MalformedLedgerEvent extends WebhooksError {
  readonly code = 'malformed-ledger-event';
}

/** The envelope written by the ledger outbox relay, forwarded to subscribers as-is. */
export interface LedgerEventEnvelope {
  eventId: string;
  eventName: string;
  eventVersion: number;
  occurredAt: string;
  aggregateId: string;
  payload: Record<string, unknown>;
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const ISO_8601 = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/;

const isObject = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

export class LedgerEvent {
  private constructor(readonly envelope: LedgerEventEnvelope) {}

  /** Accepts any event name: an unknown one is valid and simply matches no subscription. */
  static parse(input: unknown): LedgerEvent {
    if (!isObject(input)) {
      throw new MalformedLedgerEvent('The envelope must be a JSON object.');
    }
    const { eventId, eventName, eventVersion, occurredAt, aggregateId, payload } = input;
    if (typeof eventId !== 'string' || !UUID.test(eventId)) {
      throw new MalformedLedgerEvent('eventId must be a UUID.');
    }
    if (typeof eventName !== 'string' || eventName === '') {
      throw new MalformedLedgerEvent('eventName must be a non-empty string.');
    }
    if (typeof eventVersion !== 'number' || !Number.isInteger(eventVersion) || eventVersion < 1) {
      throw new MalformedLedgerEvent('eventVersion must be a positive integer.');
    }
    if (typeof occurredAt !== 'string' || !ISO_8601.test(occurredAt) || Number.isNaN(Date.parse(occurredAt))) {
      throw new MalformedLedgerEvent('occurredAt must be an ISO-8601 timestamp.');
    }
    if (typeof aggregateId !== 'string' || !UUID.test(aggregateId)) {
      throw new MalformedLedgerEvent('aggregateId must be a UUID.');
    }
    if (!isObject(payload)) {
      throw new MalformedLedgerEvent('payload must be a JSON object.');
    }

    return new LedgerEvent({ eventId, eventName, eventVersion, occurredAt, aggregateId, payload });
  }

  get eventId(): string {
    return this.envelope.eventId;
  }

  get eventName(): string {
    return this.envelope.eventName;
  }
}
