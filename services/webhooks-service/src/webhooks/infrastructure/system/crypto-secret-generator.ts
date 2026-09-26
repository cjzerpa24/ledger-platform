import { randomBytes } from 'node:crypto';
import { SecretGenerator } from '../../application/ports/secret-generator';
import { SigningSecret } from '../../domain/subscription/signing-secret';

export class CryptoSecretGenerator implements SecretGenerator {
  generate(): SigningSecret {
    return SigningSecret.fromKey(randomBytes(32));
  }
}
