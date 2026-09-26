import { PauseSubscription, ResumeSubscription } from '../../../src/webhooks/application/change-subscription-status';
import { GetSubscription } from '../../../src/webhooks/application/get-subscription';
import { ListSubscriptions } from '../../../src/webhooks/application/list-subscriptions';
import { RegisterSubscription } from '../../../src/webhooks/application/register-subscription';
import {
  InvalidTargetUrl,
  SubscriptionNotFound,
  UnknownEventType,
} from '../../../src/webhooks/domain/subscription/errors';
import { TEST_SECRET } from '../../support/fixtures';
import { FixedClock, FixedSecretGenerator, InMemorySubscriptionRepository } from '../../support/fakes';

describe('subscription use cases', () => {
  let repository: InMemorySubscriptionRepository;
  let register: RegisterSubscription;

  beforeEach(() => {
    repository = new InMemorySubscriptionRepository();
    register = new RegisterSubscription(repository, new FixedSecretGenerator(), new FixedClock(), {
      allowInsecure: false,
      allowPrivate: false,
    });
  });

  it('registers and stores an active subscription with a generated secret', async () => {
    const created = await register.execute({
      url: 'https://example.test/hooks',
      eventTypes: ['ledger.deposit_recorded'],
      description: 'Accounting',
    });

    const stored = await new GetSubscription(repository).execute(created.id);
    expect(stored.status).toBe('active');
    expect(stored.secret.value).toBe(TEST_SECRET.value);
    expect(stored.description).toBe('Accounting');
    expect(stored.createdAt).toEqual(new Date('2026-09-25T10:00:00Z'));
  });

  it('rejects http URLs unless insecure URLs are allowed', async () => {
    await expect(
      register.execute({ url: 'http://example.test', eventTypes: ['ledger.deposit_recorded'] }),
    ).rejects.toThrow(InvalidTargetUrl);

    const lenient = new RegisterSubscription(repository, new FixedSecretGenerator(), new FixedClock(), {
      allowInsecure: true,
      allowPrivate: false,
    });
    await expect(
      lenient.execute({ url: 'http://example.test', eventTypes: ['ledger.deposit_recorded'] }),
    ).resolves.toBeDefined();
  });

  it('rejects unknown event types', async () => {
    await expect(register.execute({ url: 'https://example.test', eventTypes: ['ledger.bogus'] })).rejects.toThrow(
      UnknownEventType,
    );
  });

  it('lists subscriptions', async () => {
    await register.execute({ url: 'https://a.test', eventTypes: ['ledger.deposit_recorded'] });
    await register.execute({ url: 'https://b.test', eventTypes: ['ledger.deposit_recorded'] });

    expect(await new ListSubscriptions(repository).execute()).toHaveLength(2);
  });

  it('pauses and resumes', async () => {
    const created = await register.execute({ url: 'https://a.test', eventTypes: ['ledger.deposit_recorded'] });

    expect((await new PauseSubscription(repository).execute(created.id)).status).toBe('paused');
    expect((await repository.findById(created.id))?.status).toBe('paused');
    expect((await new ResumeSubscription(repository).execute(created.id)).status).toBe('active');
  });

  it('reports a missing subscription', async () => {
    const missing = '0199a000-0000-7000-8000-000000000000';

    await expect(new GetSubscription(repository).execute(missing)).rejects.toThrow(SubscriptionNotFound);
    await expect(new PauseSubscription(repository).execute(missing)).rejects.toThrow(SubscriptionNotFound);
  });
});
