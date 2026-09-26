<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TransferRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $sourceAccountId = '',
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $destinationAccountId = '',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: FundsRequest::AMOUNT_PATTERN, message: FundsRequest::AMOUNT_MESSAGE)]
        public string $amount = '',
        #[Assert\Length(max: 255)]
        public ?string $description = null,
    ) {}
}
