import { Delivery } from '../domain/delivery/delivery';
import { DeliveryRepository } from '../domain/delivery/delivery-repository';
import { Clock } from './ports/clock';
import { GetDelivery } from './get-delivery';

export class RetryDelivery {
  private readonly get: GetDelivery;

  constructor(
    private readonly deliveries: DeliveryRepository,
    private readonly clock: Clock,
  ) {
    this.get = new GetDelivery(deliveries);
  }

  async execute(id: string): Promise<Delivery> {
    const delivery = await this.get.execute(id);
    delivery.retry(this.clock.now());
    await this.deliveries.save(delivery);

    return delivery;
  }
}
