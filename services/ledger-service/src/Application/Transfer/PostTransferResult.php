<?php

declare(strict_types=1);

namespace App\Application\Transfer;

final readonly class PostTransferResult
{
    public function __construct(public TransferView $transfer, public bool $replayed) {}
}
