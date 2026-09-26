import { LedgerEvent, MalformedLedgerEvent } from '../../domain/event/ledger-event';

/**
 * The only place that knows Symfony Messenger's Redis framing:
 * `XADD <stream> * message {"body": "<envelope JSON>", "headers": {...}}`.
 */
export class MessengerEnvelopeDecoder {
  decode(fields: Record<string, string>): LedgerEvent {
    const message = fields.message;
    if (message === undefined) {
      throw new MalformedLedgerEvent('The stream entry has no "message" field.');
    }
    const framing = parseJson(message, 'message');
    if (typeof framing !== 'object' || framing === null || typeof (framing as { body?: unknown }).body !== 'string') {
      throw new MalformedLedgerEvent('The message must be a JSON object with a string "body".');
    }

    return LedgerEvent.parse(parseJson((framing as { body: string }).body, 'body'));
  }
}

function parseJson(text: string, what: string): unknown {
  try {
    return JSON.parse(text) as unknown;
  } catch {
    throw new MalformedLedgerEvent(`The ${what} is not valid JSON.`);
  }
}
