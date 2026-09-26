import { Controller, Get, HttpCode, Param, Post } from '@nestjs/common';
import { GetDelivery } from '../../application/get-delivery';
import { RetryDelivery } from '../../application/retry-delivery';
import { uuidParam } from './pipes';
import { presentDelivery } from './presenters';

@Controller('deliveries')
export class DeliveriesController {
  constructor(
    private readonly get: GetDelivery,
    private readonly retry: RetryDelivery,
  ) {}

  @Get(':id')
  async show(@Param('id', uuidParam('id')) id: string) {
    return presentDelivery(await this.get.execute(id));
  }

  @Post(':id/retry')
  @HttpCode(200)
  async retryDelivery(@Param('id', uuidParam('id')) id: string) {
    return presentDelivery(await this.retry.execute(id));
  }
}
