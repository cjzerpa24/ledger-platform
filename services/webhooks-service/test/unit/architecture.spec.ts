import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = join(__dirname, '../../src/webhooks');

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    return statSync(path).isDirectory() ? files(path) : path.endsWith('.ts') ? [path] : [];
  });
}

function imports(file: string): string[] {
  return [...readFileSync(file, 'utf8').matchAll(/from '([^']+)'/g)].map((m) => m[1] ?? '');
}

const FRAMEWORKS = /^(@nestjs\/|@prisma\/|ioredis|class-validator|class-transformer|pg$|nestjs-pino)/;

describe('layer rules', () => {
  it.each(files(join(ROOT, 'domain')).map((f) => [relative(ROOT, f), f]))(
    'domain file %s imports no framework, application or infrastructure code',
    (_name, file) => {
      for (const source of imports(file)) {
        expect(source).not.toMatch(FRAMEWORKS);
        expect(source).not.toMatch(/\/(application|infrastructure|generated)\//);
      }
    },
  );

  it.each(files(join(ROOT, 'application')).map((f) => [relative(ROOT, f), f]))(
    'application file %s imports no framework or infrastructure code',
    (_name, file) => {
      for (const source of imports(file)) {
        expect(source).not.toMatch(FRAMEWORKS);
        expect(source).not.toMatch(/\/(infrastructure|generated)\//);
      }
    },
  );
});
