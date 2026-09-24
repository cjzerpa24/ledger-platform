<?php

declare(strict_types=1);

namespace App\Application\Port;

use Psr\Clock\ClockInterface;

/**
 * The ledger's notion of "now", at the precision it is stored with
 * (whole seconds), so a value read back from the database equals the one
 * that was written and idempotent replays return identical bodies.
 * Events inside the same second are ordered by their UUIDv7 ids.
 */
interface Clock extends ClockInterface {}
