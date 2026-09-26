import { Body, Controller, Get, HttpCode, Param, Post, Query } from '@nestjs/common';
import { PauseSubscription, ResumeSubscription } from '../../application/change-subscription-status';
import { GetSubscription } from '../../application/get-subscription';
import { ListDeliveries } from '../../application/list-deliveries';
import { ListSubscriptions } from '../../application/list-subscriptions';
import { RegisterSubscription } from '../../application/register-subscription';
import { CreateSubscriptionDto, ListDeliveriesQuery } from './dto';
import { uuidParam } from './pipes';
import { presentDeliveryPage, presentSubscription } from './presenters';

@Controller('subscriptions')
export class SubscriptionsController {
  constructor(
    private readonly register: RegisterSubscription,
    private readonly list: ListSubscriptions,
    private readonly get: GetSubscription,
    private readonly pause: PauseSubscription,
    private readonly resume: ResumeSubscription,
    private readonly listDeliveries: ListDeliveries,
  ) {}

  @Post()
  async create(@Body() body: CreateSubscriptionDto) {
    return presentSubscription(await this.register.execute(body), { withSecret: true });
  }

  @Get()
  async index() {
    return { items: (await this.list.execute()).map((s) => presentSubscription(s)) };
  }

  @Get(':id')
  async show(@Param('id', uuidParam('id')) id: string) {
    return presentSubscription(await this.get.execute(id));
  }

  @Post(':id/pause')
  @HttpCode(200)
  async pauseSubscription(@Param('id', uuidParam('id')) id: string) {
    return presentSubscription(await this.pause.execute(id));
  }

  @Post(':id/resume')
  @HttpCode(200)
  async resumeSubscription(@Param('id', uuidParam('id')) id: string) {
    return presentSubscription(await this.resume.execute(id));
  }

  @Get(':id/deliveries')
  async deliveries(@Param('id', uuidParam('id')) id: string, @Query() query: ListDeliveriesQuery) {
    return presentDeliveryPage(
      await this.listDeliveries.execute({
        subscriptionId: id,
        status: query.status,
        limit: query.limit,
        cursor: query.cursor,
      }),
    );
  }
}
