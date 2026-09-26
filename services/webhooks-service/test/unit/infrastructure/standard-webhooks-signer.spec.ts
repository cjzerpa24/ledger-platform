import { StandardWebhooksSigner } from '../../../src/webhooks/infrastructure/delivery/standard-webhooks-signer';
import { SigningSecret } from '../../../src/webhooks/domain/subscription/signing-secret';

describe('StandardWebhooksSigner', () => {
  it('matches the Standard Webhooks specification test vector', () => {
    const signature = new StandardWebhooksSigner().sign({
      id: 'msg_p5jXN8AQM9LWM0D4loKWxJek',
      timestamp: 1614265330,
      body: '{"test": 2432232314}',
      secret: SigningSecret.fromPersistence('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'),
    });

    expect(signature).toBe('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=');
  });
});
