import { SigningSecret } from '../../domain/subscription/signing-secret';

export const SIGNER = Symbol('Signer');

export interface SignInput {
  id: string;
  /** Unix seconds. */
  timestamp: number;
  body: string;
  secret: SigningSecret;
}

export interface Signer {
  /** Returns the `webhook-signature` header value. */
  sign(input: SignInput): string;
}
