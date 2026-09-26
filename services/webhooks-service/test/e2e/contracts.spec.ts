import Ajv2020 from 'ajv/dist/2020';
import addFormats from 'ajv-formats';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { v7 } from 'uuid';
import { envelope } from '../support/fixtures';

/** The fixtures the tests publish must match the shared contracts ledger-service is held to. */
describe('event contracts', () => {
  const dir = process.env.CONTRACTS_DIR ?? join(__dirname, '../../../../contracts');
  const ajv = new Ajv2020({ strict: true, allErrors: true });
  addFormats(ajv);
  const schemas = Object.fromEntries(
    readdirSync(join(dir, 'events'))
      .filter((file) => file.endsWith('.v1.schema.json'))
      .map((file) => [
        file.replace('.v1.schema.json', ''),
        JSON.parse(readFileSync(join(dir, 'events', file), 'utf8')),
      ]),
  );

  it('has a schema for every event type a subscription can choose', () => {
    expect(Object.keys(schemas).sort()).toEqual([
      'ledger.account_opened',
      'ledger.deposit_recorded',
      'ledger.transfer_completed',
      'ledger.withdrawal_recorded',
    ]);
  });

  it.each(['ledger.transfer_completed', 'ledger.deposit_recorded', 'ledger.withdrawal_recorded'])(
    'the money-movement fixture is a valid %s envelope',
    (name) => {
      const validate = ajv.compile(schemas[name]);
      expect(validate(envelope({ eventName: name }))).toBe(true);
    },
  );

  it('an account_opened envelope is valid', () => {
    const validate = ajv.compile(schemas['ledger.account_opened']);
    const accountId = v7();

    const ok = validate(
      envelope({
        eventName: 'ledger.account_opened',
        aggregateId: accountId,
        payload: { accountId, name: 'Alice', type: 'customer', currency: 'USD', openedAt: '2026-09-25T10:00:00+00:00' },
      }),
    );

    expect(validate.errors ?? []).toEqual([]);
    expect(ok).toBe(true);
  });
});
