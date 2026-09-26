import { isBlockedAddress, isLocalhostName } from './blocked-address';
import { InvalidTargetUrl } from './errors';

const MAX_LENGTH = 2048;

export class TargetUrl {
  private constructor(readonly value: string) {}

  static create(raw: string, options: { allowInsecure: boolean; allowPrivate?: boolean }): TargetUrl {
    if (raw.length > MAX_LENGTH) {
      throw new InvalidTargetUrl(`The URL must be at most ${MAX_LENGTH} characters.`);
    }
    let url: URL;
    try {
      url = new URL(raw);
    } catch {
      throw new InvalidTargetUrl('The URL must be absolute.');
    }
    const allowed = options.allowInsecure ? ['https:', 'http:'] : ['https:'];
    if (!allowed.includes(url.protocol)) {
      throw new InvalidTargetUrl(`The URL must use ${options.allowInsecure ? 'http or https' : 'https'}.`);
    }
    if (url.username !== '' || url.password !== '') {
      throw new InvalidTargetUrl('The URL must not contain credentials.');
    }
    if (options.allowPrivate !== true && (isLocalhostName(url.hostname) || isBlockedAddress(url.hostname))) {
      throw new InvalidTargetUrl('The URL must not point at a private, loopback or reserved address.');
    }

    return new TargetUrl(url.toString());
  }

  static fromPersistence(value: string): TargetUrl {
    return new TargetUrl(value);
  }
}
