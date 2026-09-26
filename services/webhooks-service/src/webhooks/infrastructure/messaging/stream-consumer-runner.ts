import { Logger, OnApplicationBootstrap, OnApplicationShutdown } from '@nestjs/common';
import { RedisStreamConsumer } from './redis-stream-consumer';

/** Runs the consumer loop in the background while the app is up (role `consumer`). */
export class StreamConsumerRunner implements OnApplicationBootstrap, OnApplicationShutdown {
  private readonly logger = new Logger(StreamConsumerRunner.name);
  private readonly abort = new AbortController();
  private running: Promise<void> | null = null;

  constructor(
    private readonly consumer: RedisStreamConsumer,
    private readonly enabled: boolean,
  ) {}

  onApplicationBootstrap(): void {
    if (!this.enabled) {
      return;
    }
    this.logger.log('Consuming the ledger event stream');
    this.running = this.consumer.run(this.abort.signal).catch((error: unknown) => {
      this.logger.error(`Stream consumer stopped: ${error instanceof Error ? error.message : String(error)}`);
    });
  }

  async onApplicationShutdown(): Promise<void> {
    this.abort.abort();
    await this.running;
    await this.consumer.close();
  }
}
