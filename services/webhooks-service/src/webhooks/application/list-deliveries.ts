import { Delivery, DeliveryStatus } from '../domain/delivery/delivery';
import { DeliveryRepository } from '../domain/delivery/delivery-repository';
import { SubscriptionRepository } from '../domain/subscription/subscription-repository';
import { GetSubscription } from './get-subscription';

export interface ListDeliveriesInput {
  subscriptionId: string;
  status?: DeliveryStatus;
  limit: number;
  cursor?: string;
}

export interface DeliveryPage {
  items: Delivery[];
  nextCursor: string | null;
}

export class ListDeliveries {
  private readonly getSubscription: GetSubscription;

  constructor(
    subscriptions: SubscriptionRepository,
    private readonly deliveries: DeliveryRepository,
  ) {
    this.getSubscription = new GetSubscription(subscriptions);
  }

  async execute(input: ListDeliveriesInput): Promise<DeliveryPage> {
    await this.getSubscription.execute(input.subscriptionId);
    const rows = await this.deliveries.listBySubscription(input.subscriptionId, {
      status: input.status,
      limit: input.limit + 1,
      before: input.cursor,
    });
    const items = rows.slice(0, input.limit);
    const last = items.at(-1);

    return { items, nextCursor: rows.length > input.limit && last !== undefined ? last.id : null };
  }
}
