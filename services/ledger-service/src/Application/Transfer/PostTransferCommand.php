<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Transfer\TransferKind;
use Symfony\Component\Uid\Uuid;

final readonly class PostTransferCommand
{
    /** Fingerprint of the request, compared when an idempotency key is replayed. */
    public string $requestHash;

    public function __construct(
        public TransferKind $kind,
        public Uuid $sourceAccountId,
        public Uuid $destinationAccountId,
        public string $amount,
        public ?string $description,
        public string $idempotencyKey,
    ) {
        $this->requestHash = hash('sha256', json_encode([
            $kind->value,
            $sourceAccountId->toRfc4122(),
            $destinationAccountId->toRfc4122(),
            trim($amount),
            $description,
        ], \JSON_THROW_ON_ERROR));
    }
}
