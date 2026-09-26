import { v7 } from 'uuid';
import { Delivery } from '../../domain/delivery/delivery';
import { DeliveryPageQuery, DeliveryRepository } from '../../domain/delivery/delivery-repository';
import { fromDelivery, toDelivery } from './mappers';
import { PrismaService } from './prisma.service';

export class PrismaDeliveryRepository implements DeliveryRepository {
  constructor(private readonly prisma: PrismaService) {}

  async addIfAbsent(deliveries: readonly Delivery[]): Promise<number> {
    if (deliveries.length === 0) {
      return 0;
    }
    // One multi-row INSERT … ON CONFLICT DO NOTHING: atomic, and a replayed event is a no-op.
    const result = await this.prisma.delivery.createMany({ data: deliveries.map(fromDelivery), skipDuplicates: true });

    return result.count;
  }

  async findById(id: string): Promise<Delivery | null> {
    const row = await this.prisma.delivery.findUnique({ where: { id }, include: { attempts: true } });
    return row === null ? null : toDelivery(row);
  }

  async save(delivery: Delivery): Promise<void> {
    const d = delivery.snapshot();
    await this.prisma.$transaction([
      this.prisma.delivery.update({
        where: { id: d.id },
        data: {
          status: d.status,
          attemptCount: d.attemptCount,
          nextAttemptAt: d.nextAttemptAt,
          lastError: d.lastError,
        },
      }),
      this.prisma.deliveryAttempt.createMany({
        data: d.attempts.map((a) => ({ id: v7(), deliveryId: d.id, ...a })),
        skipDuplicates: true,
      }),
    ]);
  }

  async claimDue(now: Date, limit: number, leaseUntil: Date): Promise<Delivery[]> {
    const claimed = await this.prisma.$queryRaw<{ id: string }[]>`
      UPDATE deliveries SET next_attempt_at = ${leaseUntil}
      WHERE id IN (
        SELECT d.id FROM deliveries d
        JOIN subscriptions s ON s.id = d.subscription_id
        WHERE d.status = 'pending' AND d.next_attempt_at <= ${now} AND s.status = 'active'
        ORDER BY d.next_attempt_at, d.id
        LIMIT ${limit}
        FOR UPDATE OF d SKIP LOCKED
      )
      RETURNING id::text AS id`;
    if (claimed.length === 0) {
      return [];
    }
    const rows = await this.prisma.delivery.findMany({
      where: { id: { in: claimed.map((row) => row.id) } },
      include: { attempts: true },
      orderBy: { id: 'asc' },
    });

    return rows.map(toDelivery);
  }

  async listBySubscription(subscriptionId: string, query: DeliveryPageQuery): Promise<Delivery[]> {
    const rows = await this.prisma.delivery.findMany({
      where: {
        subscriptionId,
        ...(query.status === undefined ? {} : { status: query.status }),
        ...(query.before === undefined ? {} : { id: { lt: query.before } }),
      },
      include: { attempts: true },
      orderBy: { id: 'desc' },
      take: query.limit,
    });

    return rows.map(toDelivery);
  }
}
