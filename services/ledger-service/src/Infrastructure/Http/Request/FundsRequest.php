<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class FundsRequest
{
    public const string AMOUNT_PATTERN = '/^\d+(\.\d+)?$/';
    public const string AMOUNT_MESSAGE = 'Amount must be a positive decimal string such as "10.50".';

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::AMOUNT_PATTERN, message: self::AMOUNT_MESSAGE)]
        public string $amount = '',
        #[Assert\Length(max: 255)]
        public ?string $description = null,
    ) {}
}
