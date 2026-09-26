import { Delivery } from '../domain/delivery/delivery';
import { DeliveryRepository } from '../domain/delivery/delivery-repository';
import { LedgerEvent } from '../domain/event/ledger-event';
import { SubscriptionRepository } from '../domain/subscription/subscription-repository';
import { Clock } from './ports/clock';

/** Fans one ledger event out to one delivery per matching active subscription. Safe to run twice for the same event. */
export class IngestLedgerEvent {
  constructor(
    private readonly subscriptions: SubscriptionRepository,
    private readonly deliveries: DeliveryRepository,
    private readonly clock: Clock,
  ) {}

  async execute(event: LedgerEvent): Promise<number> {
    const matching = await this.subscriptions.findActiveByEventType(event.eventName);
    if (matching.length === 0) {
      return 0;
    }
    const now = this.clock.now();

    return this.deliveries.addIfAbsent(
      matching.map((subscription) => Delivery.create({ subscriptionId: subscription.id, event, now })),
    );
  }
}
