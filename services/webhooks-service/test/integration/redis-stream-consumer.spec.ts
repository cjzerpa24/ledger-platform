import { Logger } from '@nestjs/common';
import Redis from 'ioredis';
import { IngestLedgerEvent } from '../../src/webhooks/application/ingest-ledger-event';
import { LedgerEvent } from '../../src/webhooks/domain/event/ledger-event';
import { MessengerEnvelopeDecoder } from '../../src/webhooks/infrastructure/messaging/messenger-envelope-decoder';
import { RedisStreamConsumer } from '../../src/webhooks/infrastructure/messaging/redis-stream-consumer';
import { envelope } from '../support/fixtures';
import { testConfig } from '../support/environment';
import { messengerFrame } from '../support/messenger';

class RecordingIngest {
  readonly events: LedgerEvent[] = [];
  failNext = 0;

  async execute(event: LedgerEvent): Promise<number> {
    if (this.failNext > 0) {
      this.failNext -= 1;
      throw new Error('database unavailable');
    }
    this.events.push(event);
    return 1;
  }
}

describe('RedisStreamConsumer', () => {
  const redis = new Redis(testConfig().redisUrl);
  let stream: string;
  let ingest: RecordingIngest;

  const consumer = (name = 'test-consumer', claimIdleMs = 60_000) =>
    new RedisStreamConsumer(
      redis,
      ingest as unknown as IngestLedgerEvent,
      new MessengerEnvelopeDecoder(),
      {
        stream,
        group: 'webhook-service',
        consumer: name,
        count: 50,
        blockMs: 100,
        retryDelayMs: 10,
        claimIdleMs,
        claimIntervalMs: 30_000,
      },
      new Logger('test', { timestamp: false }),
    );
  const pending = async () => ((await redis.xpending(stream, 'webhook-service')) as [number])[0];
  const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
  /** Leaves one entry read but unacknowledged under `crashed-replica`, as if that replica died mid-batch. */
  const strandEntry = async () => {
    const crashed = consumer('crashed-replica');
    await crashed.ensureGroup();
    const id = await redis.xadd(stream, '*', 'message', messengerFrame(envelope()));
    ingest.failNext = 1;
    await expect(crashed.processBatch('>')).rejects.toThrow('database unavailable');
    expect(await pending()).toBe(1);
    return id as string;
  };

  beforeEach(() => {
    stream = `ledger_events_test_${Date.now()}_${Math.random().toString(36).slice(2)}`;
    ingest = new RecordingIngest();
    Logger.overrideLogger(false);
  });
  afterEach(() => redis.del(stream));
  afterAll(() => redis.quit());

  it('creates the group once and only sees entries added after it', async () => {
    await redis.xadd(stream, '*', 'message', messengerFrame(envelope()));
    const c = consumer();
    await c.ensureGroup();
    await c.ensureGroup();

    expect(await c.processBatch('>')).toBe(0);
    await redis.xadd(stream, '*', 'message', messengerFrame(envelope()));
    expect(await c.processBatch('>')).toBe(1);
  });

  it('ingests entries and acknowledges them', async () => {
    const c = consumer();
    await c.ensureGroup();
    const raw = envelope();
    await redis.xadd(stream, '*', 'message', messengerFrame(raw));

    expect(await c.processBatch('>')).toBe(1);
    expect(ingest.events.map((e) => e.envelope)).toEqual([raw]);
    expect(await pending()).toBe(0);
  });

  it('acknowledges and skips malformed entries', async () => {
    const c = consumer();
    await c.ensureGroup();
    await redis.xadd(stream, '*', 'message', 'not json');
    await redis.xadd(stream, '*', 'other', 'field');
    await redis.xadd(stream, '*', 'message', messengerFrame(envelope()));

    expect(await c.processBatch('>')).toBe(3);
    expect(ingest.events).toHaveLength(1);
    expect(await pending()).toBe(0);
  });

  it('leaves an entry pending when storing it fails, and drains it after a restart', async () => {
    const first = consumer('replica-1');
    await first.ensureGroup();
    await redis.xadd(stream, '*', 'message', messengerFrame(envelope()));
    ingest.failNext = 1;

    await expect(first.processBatch('>')).rejects.toThrow('database unavailable');
    expect(await pending()).toBe(1);

    const restarted = consumer('replica-1');
    expect(await restarted.processBatch('0')).toBe(1);
    expect(ingest.events).toHaveLength(1);
    expect(await pending()).toBe(0);
    expect(await restarted.processBatch('0')).toBe(0);
  });

  it('run() retries pending entries until storing succeeds, then stops on abort', async () => {
    const c = consumer();
    await c.ensureGroup();
    await redis.xadd(stream, '*', 'message', messengerFrame(envelope()));
    ingest.failNext = 2;
    const abort = new AbortController();

    const running = c.run(abort.signal);
    const deadline = Date.now() + 5000;
    while (ingest.events.length === 0 && Date.now() < deadline) {
      await new Promise((resolve) => setTimeout(resolve, 20));
    }
    abort.abort();
    await running;

    expect(ingest.events).toHaveLength(1);
    expect(await pending()).toBe(0);
  });

  it('takes over an entry left pending by another consumer once it has been idle long enough', async () => {
    await strandEntry();
    await wait(80);

    const live = consumer('live-replica', 50);
    expect(await live.claimStale()).toBe(1);
    expect(ingest.events).toHaveLength(1);
    expect(await pending()).toBe(0);
  });

  it('does not take over an entry that is still within the idle time', async () => {
    await strandEntry();

    const live = consumer('live-replica', 60_000);
    expect(await live.claimStale()).toBe(0);
    expect(ingest.events).toHaveLength(0);
    expect(await pending()).toBe(1);
  });

  it('acknowledges pending entries whose stream entry no longer exists', async () => {
    const id = await strandEntry();
    await redis.xdel(stream, id);
    await wait(80);

    const live = consumer('live-replica', 50);
    expect(await live.claimStale()).toBe(0);
    expect(ingest.events).toHaveLength(0);
    expect(await pending()).toBe(0);
  });

  it('run() claims entries stranded by a crashed replica on startup', async () => {
    await strandEntry();
    await wait(80);
    const abort = new AbortController();

    const running = consumer('live-replica', 50).run(abort.signal);
    const deadline = Date.now() + 5000;
    while (ingest.events.length === 0 && Date.now() < deadline) {
      await wait(20);
    }
    abort.abort();
    await running;

    expect(ingest.events).toHaveLength(1);
    expect(await pending()).toBe(0);
  });
});
