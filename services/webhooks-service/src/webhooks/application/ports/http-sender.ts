export const HTTP_SENDER = Symbol('HttpSender');

export interface OutgoingRequest {
  url: string;
  headers: Record<string, string>;
  body: string;
  timeoutMs: number;
}

/** `statusCode` is null when no response arrived; `error` is set for timeouts and network failures. */
export interface SendResult {
  statusCode: number | null;
  error: string | null;
  durationMs: number;
}

export interface HttpSender {
  send(request: OutgoingRequest): Promise<SendResult>;
}
