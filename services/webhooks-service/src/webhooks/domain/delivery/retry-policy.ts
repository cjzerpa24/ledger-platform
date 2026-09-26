const SECOND = 1_000;
const MINUTE = 60 * SECOND;
const HOUR = 60 * MINUTE;

/** Fixed backoff schedule. A delivery is dead after `maxAttempts` failures. No jitter, so tests are deterministic. */
export class RetryPolicy {
  static readonly DEFAULT = new RetryPolicy([
    10 * SECOND,
    30 * SECOND,
    2 * MINUTE,
    10 * MINUTE,
    30 * MINUTE,
    HOUR,
    3 * HOUR,
  ]);

  constructor(private readonly delaysMs: readonly number[]) {}

  get maxAttempts(): number {
    return this.delaysMs.length + 1;
  }

  /** Delay before the next attempt, after `failedAttempts` failures (1-based). */
  delayAfter(failedAttempts: number): number {
    const delay = this.delaysMs[failedAttempts - 1];
    if (delay === undefined) {
      throw new RangeError(`No retry after ${failedAttempts} failed attempts.`);
    }

    return delay;
  }
}
