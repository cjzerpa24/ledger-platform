import { SigningSecret } from '../../domain/subscription/signing-secret';

export const SECRET_GENERATOR = Symbol('SecretGenerator');

export interface SecretGenerator {
  generate(): SigningSecret;
}
