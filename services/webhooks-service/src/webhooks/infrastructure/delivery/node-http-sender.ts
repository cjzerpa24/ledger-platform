import { LookupAddress, lookup as dnsLookup } from 'node:dns';
import { request as httpRequest, RequestOptions } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { LookupFunction } from 'node:net';
import { performance } from 'node:perf_hooks';
import { HttpSender, OutgoingRequest, SendResult } from '../../application/ports/http-sender';
import { isBlockedAddress } from '../../domain/subscription/blocked-address';

class BlockedTarget extends Error {}

/**
 * POSTs with node:http(s) rather than fetch, so every connection goes through a `lookup` hook that refuses
 * private and reserved addresses. Checking at connect time, not only at registration, defeats DNS rebinding.
 * Redirects are never followed.
 */
export class NodeHttpSender implements HttpSender {
  constructor(private readonly options: { allowPrivateTargets: boolean }) {}

  send(outgoing: OutgoingRequest): Promise<SendResult> {
    const started = performance.now();
    const elapsed = () => Math.round(performance.now() - started);

    return new Promise<SendResult>((resolve) => {
      const fail = (error: string) => resolve({ statusCode: null, error, durationMs: elapsed() });
      let url: URL;
      try {
        url = new URL(outgoing.url);
      } catch {
        fail('invalid URL');
        return;
      }
      if (!this.options.allowPrivateTargets && isBlockedAddress(url.hostname)) {
        fail(`${url.hostname} is a blocked address`);
        return;
      }

      const body = Buffer.from(outgoing.body);
      const options: RequestOptions = {
        method: 'POST',
        headers: { ...outgoing.headers, 'content-length': String(body.length) },
        signal: AbortSignal.timeout(outgoing.timeoutMs),
        lookup: this.options.allowPrivateTargets ? undefined : guardedLookup,
      };
      const request = (url.protocol === 'https:' ? httpsRequest : httpRequest)(url, options, (response) => {
        response.resume();
        resolve({ statusCode: response.statusCode ?? null, error: null, durationMs: elapsed() });
      });
      request.on('error', (error: Error) => {
        if (error.name === 'AbortError' || error.name === 'TimeoutError') {
          fail(`timeout after ${outgoing.timeoutMs}ms`);
        } else if (error instanceof BlockedTarget) {
          fail(error.message);
        } else {
          fail(error.message || error.name);
        }
      });
      request.end(body);
    });
  }
}

const guardedLookup: LookupFunction = (hostname, options, callback) => {
  dnsLookup(hostname, { ...options, all: true }, (error, addresses: LookupAddress[]) => {
    if (error) {
      callback(error, '', 0);
      return;
    }
    const blocked = addresses.filter((entry) => isBlockedAddress(entry.address));
    if (blocked.length > 0) {
      const list = blocked.map((entry) => entry.address).join(', ');
      callback(new BlockedTarget(`${hostname} resolves to a blocked address (${list})`), '', 0);
      return;
    }
    if (options.all) {
      (callback as unknown as (err: null, addresses: LookupAddress[]) => void)(null, addresses);
      return;
    }
    const first = addresses[0];
    if (first === undefined) {
      callback(new Error(`${hostname} did not resolve`), '', 0);
      return;
    }
    callback(null, first.address, first.family);
  });
};
