<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OpenAccountRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $name = '',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^[A-Z]{3}$/', message: 'Currency must be a three-letter uppercase ISO-4217 code.')]
        public string $currency = '',
    ) {}
}
