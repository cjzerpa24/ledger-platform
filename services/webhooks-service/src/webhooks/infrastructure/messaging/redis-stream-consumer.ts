import { LoggerService } from '@nestjs/common';
import Redis from 'ioredis';
import { IngestLedgerEvent } from '../../application/ingest-ledger-event';
import { MalformedLedgerEvent } from '../../domain/event/ledger-event';
import { MessengerEnvelopeDecoder } from './messenger-envelope-decoder';

export interface StreamConsumerOptions {
  stream: string;
  group: string;
  consumer: string;
  count: number;
  blockMs: number;
  retryDelayMs: number;
}

type StreamEntry = [id: string, fields: string[]];
type XReadGroupReply = [stream: string, entries: StreamEntry[]][] | null;

const sleep = (ms: number, signal: AbortSignal) =>
  new Promise<void>((resolve) => {
    const timer = setTimeout(resolve, ms);
    signal.addEventListener('abort', () => {
      clearTimeout(timer);
      resolve();
    });
  });

/**
 * Reads the ledger stream through a consumer group. An entry is acknowledged only after its deliveries are
 * stored, so a crash replays it (deduplicated downstream). Poison entries are logged and acknowledged.
 */
export class RedisStreamConsumer {
  constructor(
    private readonly redis: Redis,
    private readonly ingest: IngestLedgerEvent,
    private readonly decoder: MessengerEnvelopeDecoder,
    private readonly options: StreamConsumerOptions,
    private readonly logger: LoggerService,
  ) {}

  async ensureGroup(): Promise<void> {
    try {
      await this.redis.xgroup('CREATE', this.options.stream, this.options.group, '$', 'MKSTREAM');
    } catch (error) {
      if (!(error instanceof Error) || !error.message.startsWith('BUSYGROUP')) {
        throw error;
      }
    }
  }

  /**
   * Reads one batch. `'0'` re-reads this consumer's pending (unacknowledged) entries, `'>'` reads new ones.
   * Returns how many entries were read; throws if storing any of them failed (they stay pending).
   */
  async processBatch(from: '0' | '>', blockMs?: number): Promise<number> {
    const block = blockMs === undefined ? [] : ['BLOCK', blockMs];
    const reply = (await this.redis.call(
      'XREADGROUP',
      'GROUP',
      this.options.group,
      this.options.consumer,
      'COUNT',
      this.options.count,
      ...block,
      'STREAMS',
      this.options.stream,
      from,
    )) as XReadGroupReply;
    const entries = reply?.[0]?.[1] ?? [];
    for (const [id, fields] of entries) {
      await this.handle(id, fields);
    }

    return entries.length;
  }

  /** Drains pending entries, then reads new ones until aborted. After a failure it goes back to the pending list. */
  async run(signal: AbortSignal): Promise<void> {
    await this.ensureGroup();
    let from: '0' | '>' = '0';
    while (!signal.aborted) {
      try {
        const read = await this.processBatch(from, from === '>' ? this.options.blockMs : undefined);
        if (from === '0' && read === 0) {
          from = '>';
        }
      } catch (error) {
        this.logger.error(`Stream batch failed; retrying pending entries: ${describe(error)}`);
        from = '0';
        await sleep(this.options.retryDelayMs, signal);
      }
    }
  }

  /** Closes the consumer's own connection. */
  async close(): Promise<void> {
    await this.redis.quit().catch(() => undefined);
  }

  private async handle(id: string, flatFields: string[]): Promise<void> {
    const fields: Record<string, string> = {};
    for (let i = 0; i + 1 < flatFields.length; i += 2) {
      fields[flatFields[i] ?? ''] = flatFields[i + 1] ?? '';
    }
    try {
      const event = this.decoder.decode(fields);
      const created = await this.ingest.execute(event);
      this.logger.log(`Ingested ${event.eventName} ${event.eventId} (entry ${id}): ${created} delivery(ies)`);
    } catch (error) {
      if (!(error instanceof MalformedLedgerEvent)) {
        throw error;
      }
      this.logger.error(`Skipping malformed stream entry ${id}: ${error.message}`);
    }
    await this.redis.xack(this.options.stream, this.options.group, id);
  }
}

function describe(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}
