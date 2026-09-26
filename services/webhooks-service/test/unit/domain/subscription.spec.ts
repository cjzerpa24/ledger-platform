import { parseEventTypes } from '../../../src/webhooks/domain/subscription/event-type';
import { isBlockedAddress } from '../../../src/webhooks/domain/subscription/blocked-address';
import { InvalidTargetUrl, UnknownEventType } from '../../../src/webhooks/domain/subscription/errors';
import { SigningSecret } from '../../../src/webhooks/domain/subscription/signing-secret';
import { TargetUrl } from '../../../src/webhooks/domain/subscription/target-url';
import { subscription } from '../../support/fixtures';

describe('TargetUrl', () => {
  it('accepts an absolute https URL', () => {
    expect(TargetUrl.create('https://example.test/hooks?x=1', { allowInsecure: false }).value).toBe(
      'https://example.test/hooks?x=1',
    );
  });

  it.each(['http://example.test/hooks', 'ftp://example.test', '/relative', 'not a url', ''])(
    'rejects %p when insecure URLs are not allowed',
    (raw) => {
      expect(() => TargetUrl.create(raw, { allowInsecure: false })).toThrow(InvalidTargetUrl);
    },
  );

  it('accepts http when insecure URLs are allowed', () => {
    expect(TargetUrl.create('http://receiver.test:8080/hook', { allowInsecure: true }).value).toBe(
      'http://receiver.test:8080/hook',
    );
  });

  it('rejects credentials in the URL', () => {
    expect(() => TargetUrl.create('https://user:pass@example.test/', { allowInsecure: false })).toThrow(
      InvalidTargetUrl,
    );
  });

  it('rejects URLs longer than 2048 characters', () => {
    expect(() => TargetUrl.create(`https://example.test/${'a'.repeat(2048)}`, { allowInsecure: false })).toThrow(
      InvalidTargetUrl,
    );
  });
});

describe('parseEventTypes', () => {
  it('keeps known types and collapses duplicates', () => {
    expect(parseEventTypes(['ledger.deposit_recorded', 'ledger.deposit_recorded', 'ledger.account_opened'])).toEqual([
      'ledger.deposit_recorded',
      'ledger.account_opened',
    ]);
  });

  it('rejects an empty list', () => {
    expect(() => parseEventTypes([])).toThrow(UnknownEventType);
  });

  it('rejects unknown types and names them', () => {
    expect(() => parseEventTypes(['ledger.deposit_recorded', 'ledger.nope'])).toThrow(/ledger\.nope/);
  });
});

describe('SigningSecret', () => {
  it('encodes the key with the whsec_ prefix and decodes it back', () => {
    const key = Buffer.alloc(32, 3);
    const secret = SigningSecret.fromKey(key);

    expect(secret.value.startsWith('whsec_')).toBe(true);
    expect(secret.key().equals(key)).toBe(true);
  });

  it('requires a 32-byte key', () => {
    expect(() => SigningSecret.fromKey(Buffer.alloc(16))).toThrow();
  });
});

describe('Subscription', () => {
  it('registers active with a v7 id', () => {
    const sub = subscription();

    expect(sub.status).toBe('active');
    expect(sub.id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7/);
  });

  it('matches only subscribed events while active', () => {
    const sub = subscription({ eventTypes: ['ledger.deposit_recorded'] });

    expect(sub.matches('ledger.deposit_recorded')).toBe(true);
    expect(sub.matches('ledger.transfer_completed')).toBe(false);

    sub.pause();
    expect(sub.matches('ledger.deposit_recorded')).toBe(false);

    sub.resume();
    expect(sub.matches('ledger.deposit_recorded')).toBe(true);
  });

  it('pause and resume are idempotent', () => {
    const sub = subscription();
    sub.pause();
    sub.pause();
    expect(sub.status).toBe('paused');
    sub.resume();
    sub.resume();
    expect(sub.status).toBe('active');
  });
});

describe('address policy', () => {
  it.each([
    '127.0.0.1',
    '10.1.2.3',
    '172.16.0.1',
    '172.31.255.255',
    '192.168.1.1',
    '169.254.169.254',
    '100.64.0.1',
    '0.0.0.0',
    '224.0.0.1',
    '255.255.255.255',
    '::1',
    '::',
    'fd00::1',
    'fe80::1',
    'ff02::1',
    '::ffff:127.0.0.1',
    '::ffff:7f00:1',
    '0:0:0:0:0:ffff:a9fe:a9fe',
    '[::1]',
  ])('blocks %s', (address) => {
    expect(isBlockedAddress(address)).toBe(true);
  });

  it.each(['8.8.8.8', '172.32.0.1', '100.128.0.1', '2606:4700::1111', '::ffff:8.8.8.8', 'example.test'])(
    'allows %s',
    (address) => {
      expect(isBlockedAddress(address)).toBe(false);
    },
  );

  it.each([
    'https://127.0.0.1/hook',
    'https://[::1]/hook',
    'https://[::ffff:10.0.0.1]/hook',
    'https://169.254.169.254/latest/meta-data',
    'https://localhost/hook',
    'https://api.localhost/hook',
    'https://2130706433/hook',
    'https://0x7f.1/hook',
  ])('TargetUrl rejects the private target %s', (raw) => {
    expect(() => TargetUrl.create(raw, { allowInsecure: false })).toThrow(/private, loopback or reserved/);
  });

  it('TargetUrl allows private targets when explicitly enabled', () => {
    expect(TargetUrl.create('http://localhost:8080/hook', { allowInsecure: true, allowPrivate: true }).value).toBe(
      'http://localhost:8080/hook',
    );
  });
});
