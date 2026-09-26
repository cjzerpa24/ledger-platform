import { Subscription } from '../../domain/subscription/subscription';
import { SubscriptionRepository } from '../../domain/subscription/subscription-repository';
import { fromSubscription, toSubscription } from './mappers';
import { PrismaService } from './prisma.service';

export class PrismaSubscriptionRepository implements SubscriptionRepository {
  constructor(private readonly prisma: PrismaService) {}

  async save(subscription: Subscription): Promise<void> {
    const row = fromSubscription(subscription);
    await this.prisma.subscription.upsert({ where: { id: row.id }, create: row, update: row });
  }

  async findById(id: string): Promise<Subscription | null> {
    const row = await this.prisma.subscription.findUnique({ where: { id } });
    return row === null ? null : toSubscription(row);
  }

  async list(): Promise<Subscription[]> {
    const rows = await this.prisma.subscription.findMany({ orderBy: [{ createdAt: 'asc' }, { id: 'asc' }] });
    return rows.map(toSubscription);
  }

  async findActiveByEventType(eventName: string): Promise<Subscription[]> {
    const rows = await this.prisma.subscription.findMany({
      where: { status: 'active', eventTypes: { has: eventName } },
      orderBy: { id: 'asc' },
    });
    return rows.map(toSubscription);
  }
}
