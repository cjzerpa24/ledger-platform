import { Logger, OnApplicationBootstrap, OnApplicationShutdown } from '@nestjs/common';
import { DispatchDueDeliveries } from '../../application/dispatch-due-deliveries';

/** Ticks DispatchDueDeliveries every interval, and immediately again after a full batch (role `dispatcher`). */
export class DispatcherLoop implements OnApplicationBootstrap, OnApplicationShutdown {
  private readonly logger = new Logger(DispatcherLoop.name);
  private timer: NodeJS.Timeout | null = null;
  private tick: Promise<void> | null = null;
  private stopped = false;

  constructor(
    private readonly dispatch: DispatchDueDeliveries,
    private readonly options: { enabled: boolean; intervalMs: number; batchSize: number },
  ) {}

  onApplicationBootstrap(): void {
    if (this.options.enabled) {
      this.logger.log('Dispatching due deliveries');
      this.schedule(0);
    }
  }

  async onApplicationShutdown(): Promise<void> {
    this.stopped = true;
    if (this.timer !== null) {
      clearTimeout(this.timer);
    }
    await this.tick;
  }

  private schedule(delayMs: number): void {
    if (this.stopped) {
      return;
    }
    this.timer = setTimeout(() => {
      this.tick = this.run();
    }, delayMs);
  }

  private async run(): Promise<void> {
    let claimed = 0;
    try {
      claimed = await this.dispatch.execute();
    } catch (error) {
      this.logger.error(`Dispatch failed: ${error instanceof Error ? error.message : String(error)}`);
    }
    this.schedule(claimed >= this.options.batchSize ? 0 : this.options.intervalMs);
  }
}
