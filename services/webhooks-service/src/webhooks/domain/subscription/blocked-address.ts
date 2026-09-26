import { BlockList, isIP } from 'node:net';

/** Private, loopback, link-local, multicast and reserved ranges a webhook must never reach (SSRF). */
const blocked = new BlockList();
for (const [network, prefix] of [
  ['0.0.0.0', 8],
  ['10.0.0.0', 8],
  ['100.64.0.0', 10],
  ['127.0.0.0', 8],
  ['169.254.0.0', 16],
  ['172.16.0.0', 12],
  ['192.168.0.0', 16],
  ['224.0.0.0', 4],
  ['240.0.0.0', 4],
] as const) {
  blocked.addSubnet(network, prefix, 'ipv4');
}
blocked.addAddress('::', 'ipv6');
blocked.addAddress('::1', 'ipv6');
for (const [network, prefix] of [
  ['fc00::', 7],
  ['fe80::', 10],
  ['ff00::', 8],
] as const) {
  blocked.addSubnet(network, prefix, 'ipv6');
}

/** An IPv4-mapped IPv6 address (`::ffff:a.b.c.d`, in any spelling) → `a.b.c.d`; anything else → null. */
function mappedIpv4(address: string): string | null {
  // The WHATWG URL parser canonicalises IPv6, e.g. `0:0:0:0:0:ffff:127.0.0.1` → `[::ffff:7f00:1]`.
  const canonical = new URL(`http://[${address}]`).hostname;
  const hex = /^\[::ffff:([0-9a-f]{1,4}):([0-9a-f]{1,4})\]$/i.exec(canonical);
  if (hex === null) {
    return null;
  }
  const high = parseInt(hex[1] ?? '0', 16);
  const low = parseInt(hex[2] ?? '0', 16);

  return [high >> 8, high & 0xff, low >> 8, low & 0xff].join('.');
}

/** True when the IP literal is in a blocked range. Non-IP input returns false. */
export function isBlockedAddress(address: string): boolean {
  const bare = address.startsWith('[') && address.endsWith(']') ? address.slice(1, -1) : address;
  const family = isIP(bare);
  if (family === 4) {
    return blocked.check(bare, 'ipv4');
  }
  if (family === 6) {
    const v4 = mappedIpv4(bare);
    return v4 !== null ? blocked.check(v4, 'ipv4') : blocked.check(bare, 'ipv6');
  }

  return false;
}

export const isLocalhostName = (host: string): boolean => {
  const name = host.toLowerCase().replace(/\.$/, '');
  return name === 'localhost' || name.endsWith('.localhost');
};
