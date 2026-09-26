import { MalformedLedgerEvent } from '../../../src/webhooks/domain/event/ledger-event';
import { MessengerEnvelopeDecoder } from '../../../src/webhooks/infrastructure/messaging/messenger-envelope-decoder';
import { envelope } from '../../support/fixtures';
import { messengerFrame } from '../../support/messenger';

describe('MessengerEnvelopeDecoder', () => {
  const decoder = new MessengerEnvelopeDecoder();

  it('unwraps the Messenger framing into a LedgerEvent', () => {
    const raw = envelope();

    expect(decoder.decode({ message: messengerFrame(raw) }).envelope).toEqual(raw);
  });

  it.each<[string, Record<string, string>]>([
    ['no message field', { other: 'x' }],
    ['a message that is not JSON', { message: 'not json' }],
    ['a PHP-serialized message (serializer option left at its default)', { message: 's:12:"{\\"body\\":1}";' }],
    ['a body that is not a string', { message: JSON.stringify({ body: { eventId: 'x' } }) }],
    ['a body that is not JSON', { message: JSON.stringify({ body: 'O:8:"stdClass":0:{}' }) }],
    ['a body that is not an envelope', { message: messengerFrame({ hello: 'world' }) }],
  ])('rejects %s', (_label, fields) => {
    expect(() => decoder.decode(fields)).toThrow(MalformedLedgerEvent);
  });
});
