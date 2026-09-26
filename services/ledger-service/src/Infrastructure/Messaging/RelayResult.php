<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

final readonly class RelayResult
{
    public function __construct(public int $published, public int $failed) {}
}
