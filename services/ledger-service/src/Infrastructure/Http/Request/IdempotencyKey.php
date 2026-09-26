<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

final readonly class IdempotencyKey
{
    public function __construct(public string $value) {}
}
