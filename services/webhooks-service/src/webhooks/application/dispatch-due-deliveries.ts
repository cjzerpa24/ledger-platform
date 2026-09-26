import { Delivery } from '../domain/delivery/delivery';
import { DeliveryRepository } from '../domain/delivery/delivery-repository';
import { RetryPolicy } from '../domain/delivery/retry-policy';
import { SubscriptionRepository } from '../domain/subscription/subscription-repository';
import { Clock } from './ports/clock';
import { HttpSender, SendResult } from './ports/http-sender';
import { Signer } from './ports/signer';

export interface DispatchOptions {
  batchSize: number;
  leaseMs: number;
  timeoutMs: number;
  policy: RetryPolicy;
}

const USER_AGENT = 'ledger-platform-webhooks/1';

const isSuccess = (result: SendResult): result is SendResult & { statusCode: number } =>
  result.error === null && result.statusCode !== null && result.statusCode >= 200 && result.statusCode < 300;

/** Claims due deliveries, sends them signed, and records each outcome. Returns how many were claimed. */
export class DispatchDueDeliveries {
  constructor(
    private readonly deliveries: DeliveryRepository,
    private readonly subscriptions: SubscriptionRepository,
    private readonly sender: HttpSender,
    private readonly signer: Signer,
    private readonly clock: Clock,
    private readonly options: DispatchOptions,
  ) {}

  async execute(): Promise<number> {
    const now = this.clock.now();
    const claimed = await this.deliveries.claimDue(
      now,
      this.options.batchSize,
      new Date(now.getTime() + this.options.leaseMs),
    );
    await Promise.all(claimed.map((delivery) => this.deliver(delivery)));

    return claimed.length;
  }

  private async deliver(delivery: Delivery): Promise<void> {
    const subscription = await this.subscriptions.findById(delivery.subscriptionId);
    if (subscription === null) {
      return;
    }
    const body = JSON.stringify(delivery.body);
    const timestamp = Math.floor(this.clock.now().getTime() / 1000);
    const result = await this.sender.send({
      url: subscription.url.value,
      headers: {
        'content-type': 'application/json',
        'user-agent': USER_AGENT,
        'webhook-id': delivery.eventId,
        'webhook-timestamp': String(timestamp),
        'webhook-signature': this.signer.sign({ id: delivery.eventId, timestamp, body, secret: subscription.secret }),
      },
      body,
      timeoutMs: this.options.timeoutMs,
    });

    const at = this.clock.now();
    if (isSuccess(result)) {
      delivery.recordSuccess(result.statusCode, result.durationMs, at);
    } else {
      delivery.recordFailure(
        { statusCode: result.statusCode, error: result.error ?? `HTTP ${result.statusCode}` },
        result.durationMs,
        at,
        this.options.policy,
      );
    }
    await this.deliveries.save(delivery);
  }
}
