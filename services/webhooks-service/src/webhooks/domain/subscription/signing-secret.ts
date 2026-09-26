const PREFIX = 'whsec_';
const KEY_BYTES = 32;

/** A Standard Webhooks secret: `whsec_` + base64 of the HMAC key. */
export class SigningSecret {
  private constructor(readonly value: string) {}

  static fromKey(key: Uint8Array): SigningSecret {
    if (key.length !== KEY_BYTES) {
      throw new Error(`A signing key must be ${KEY_BYTES} bytes.`);
    }

    return new SigningSecret(PREFIX + Buffer.from(key).toString('base64'));
  }

  static fromPersistence(value: string): SigningSecret {
    if (!value.startsWith(PREFIX)) {
      throw new Error('A signing secret must start with whsec_.');
    }

    return new SigningSecret(value);
  }

  key(): Buffer {
    return Buffer.from(this.value.slice(PREFIX.length), 'base64');
  }
}
