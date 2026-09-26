import { Delivery } from '../domain/delivery/delivery';
import { DeliveryRepository } from '../domain/delivery/delivery-repository';
import { DeliveryNotFound } from '../domain/delivery/errors';

export class GetDelivery {
  constructor(private readonly deliveries: DeliveryRepository) {}

  async execute(id: string): Promise<Delivery> {
    const delivery = await this.deliveries.findById(id);
    if (delivery === null) {
      throw new DeliveryNotFound(id);
    }

    return delivery;
  }
}
