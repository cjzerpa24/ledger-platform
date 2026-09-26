import { NodeHttpSender } from '../../src/webhooks/infrastructure/delivery/node-http-sender';
import { WebhookReceiver } from '../support/webhook-receiver';

describe('NodeHttpSender', () => {
  const receiver = new WebhookReceiver();
  const lenient = new NodeHttpSender({ allowPrivateTargets: true });
  const strict = new NodeHttpSender({ allowPrivateTargets: false });
  const post = (sender: NodeHttpSender, url: string, timeoutMs = 2000) =>
    sender.send({ url, headers: { 'content-type': 'application/json', 'x-test': '1' }, body: '{"a":1}', timeoutMs });

  beforeAll(() => receiver.start());
  afterAll(() => receiver.stop());
  beforeEach(() => receiver.respondWith(204));

  it('POSTs the body and headers and reports the status', async () => {
    const result = await post(lenient, receiver.url('/hook?x=1'));

    expect(result).toMatchObject({ statusCode: 204, error: null });
    const request = receiver.requests.at(-1);
    expect(request).toMatchObject({ method: 'POST', path: '/hook?x=1', body: '{"a":1}' });
    expect(request?.headers['x-test']).toBe('1');
    expect(request?.headers['content-length']).toBe('7');
  });

  it('reports non-2xx statuses without an error', async () => {
    receiver.respondWith(500);

    expect(await post(lenient, receiver.url())).toMatchObject({ statusCode: 500, error: null });
  });

  it('does not follow redirects', async () => {
    receiver.respondWith(302, { headers: { location: 'http://169.254.169.254/' } });
    const before = receiver.requests.length;

    expect(await post(lenient, receiver.url())).toMatchObject({ statusCode: 302, error: null });
    expect(receiver.requests.length).toBe(before + 1);
  });

  it('times out slow receivers', async () => {
    receiver.respondWith(200, { delayMs: 1000 });

    expect(await post(lenient, receiver.url(), 200)).toMatchObject({ statusCode: null, error: 'timeout after 200ms' });
  });

  it('reports connection failures', async () => {
    const result = await post(lenient, 'http://127.0.0.1:1/hook');

    expect(result.statusCode).toBeNull();
    expect(result.error).toMatch(/ECONNREFUSED/);
  });

  it('refuses private IP literals when private targets are not allowed', async () => {
    const before = receiver.requests.length;

    expect(await post(strict, receiver.url())).toMatchObject({
      statusCode: null,
      error: '127.0.0.1 is a blocked address',
    });
    expect(receiver.requests.length).toBe(before);
  });

  it('refuses host names that resolve to private addresses (checked at connect time)', async () => {
    const port = new URL(receiver.url()).port;
    const before = receiver.requests.length;

    const result = await post(strict, `http://localhost:${port}/hook`);

    expect(result.statusCode).toBeNull();
    expect(result.error).toMatch(/^localhost resolves to a blocked address \(/);
    expect(receiver.requests.length).toBe(before);
  });
});
