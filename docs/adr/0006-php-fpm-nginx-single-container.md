# 0006. php-fpm and nginx in one container

## Status

Accepted, 2026-09-24

## Context

A PHP API needs a web server in front of PHP. The usual compose setup runs
nginx and php-fpm as two containers sharing a volume, which doubles the
moving parts for a single service and complicates health checks.

## Decision

- One Alpine image (`php:8.4-fpm-alpine`) runs php-fpm and nginx under
  supervisord, with nginx on port 80.
- The same image runs the outbox relay as a separate compose service
  (`ledger-outbox-relay`) with a different command, so the API and the relay
  scale and restart independently.
- A FrankenPHP runtime is deferred to a future experiment.

## Consequences

- One image to build, scan and deploy. `GET /health` goes through nginx and
  php-fpm to the database, so one probe covers the whole path; the image and
  compose don't define a `HEALTHCHECK` yet.
- supervisord is an extra process manager inside the container; a crash of
  either nginx or php-fpm is restarted by it rather than by the orchestrator.
- The dev target mounts the source and remaps `www-data` to the host UID so
  files written by tools stay owned by the developer.
