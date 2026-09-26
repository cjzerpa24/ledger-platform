import { createServer, IncomingHttpHeaders, Server } from 'node:http';
import { AddressInfo } from 'node:net';

export interface ReceivedRequest {
  method: string;
  path: string;
  headers: IncomingHttpHeaders;
  body: string;
}

/** A local HTTP endpoint standing in for a subscriber. */
export class WebhookReceiver {
  readonly requests: ReceivedRequest[] = [];
  private status = 204;
  private delayMs = 0;
  private headers: Record<string, string> = {};
  private readonly server: Server;

  constructor() {
    this.server = createServer((req, res) => {
      const chunks: Buffer[] = [];
      req.on('data', (chunk: Buffer) => chunks.push(chunk));
      req.on('end', () => {
        this.requests.push({
          method: req.method ?? '',
          path: req.url ?? '',
          headers: req.headers,
          body: Buffer.concat(chunks).toString(),
        });
        setTimeout(() => res.writeHead(this.status, this.headers).end(), this.delayMs);
      });
    });
  }

  async start(): Promise<void> {
    await new Promise<void>((resolve) => this.server.listen(0, '127.0.0.1', resolve));
  }

  async stop(): Promise<void> {
    this.server.closeAllConnections();
    await new Promise<void>((resolve) => this.server.close(() => resolve()));
  }

  url(path = '/hook'): string {
    return `http://127.0.0.1:${(this.server.address() as AddressInfo).port}${path}`;
  }

  respondWith(status: number, options: { delayMs?: number; headers?: Record<string, string> } = {}): void {
    this.status = status;
    this.delayMs = options.delayMs ?? 0;
    this.headers = options.headers ?? {};
  }
}
