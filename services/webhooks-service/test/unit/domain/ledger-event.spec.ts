import { LedgerEvent, MalformedLedgerEvent } from '../../../src/webhooks/domain/event/ledger-event';
import { envelope } from '../../support/fixtures';

describe('LedgerEvent.parse', () => {
  it('accepts a valid envelope and keeps it unchanged', () => {
    const raw = envelope();

    expect(LedgerEvent.parse(raw).envelope).toEqual(raw);
  });

  it('accepts an unknown event name', () => {
    expect(LedgerEvent.parse(envelope({ eventName: 'ledger.something_new' })).eventName).toBe('ledger.something_new');
  });

  it.each<[string, unknown]>([
    ['a non-object', 'hello'],
    ['an array', []],
    ['null', null],
    ['a non-uuid eventId', { ...envelope(), eventId: 'abc' }],
    ['an empty eventName', { ...envelope(), eventName: '' }],
    ['a zero eventVersion', { ...envelope(), eventVersion: 0 }],
    ['a fractional eventVersion', { ...envelope(), eventVersion: 1.5 }],
    ['a date-only occurredAt', { ...envelope(), occurredAt: '2026-09-25' }],
    ['an impossible occurredAt', { ...envelope(), occurredAt: '2026-13-45T99:00:00Z' }],
    ['a non-uuid aggregateId', { ...envelope(), aggregateId: 42 }],
    ['an array payload', { ...envelope(), payload: [] }],
    ['a missing payload', { ...envelope(), payload: undefined }],
  ])('rejects %s', (_label, input) => {
    expect(() => LedgerEvent.parse(input)).toThrow(MalformedLedgerEvent);
  });
});
