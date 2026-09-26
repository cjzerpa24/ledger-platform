<?php

declare(strict_types=1);

namespace App\Application\Port;

/** A write lost a race against another write with the same unique key. */
final class DuplicateRecord extends \RuntimeException {}
