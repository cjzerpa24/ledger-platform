import { createHmac } from 'node:crypto';
import { SignInput, Signer } from '../../application/ports/signer';

/** https://www.standardwebhooks.com/ : `v1,` + base64 HMAC-SHA256 of `{id}.{timestamp}.{body}`. */
export class StandardWebhooksSigner implements Signer {
  sign({ id, timestamp, body, secret }: SignInput): string {
    const digest = createHmac('sha256', secret.key()).update(`${id}.${timestamp}.${body}`).digest('base64');

    return `v1,${digest}`;
  }
}
