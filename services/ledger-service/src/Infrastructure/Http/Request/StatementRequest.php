<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class StatementRequest
{
    public function __construct(
        #[Assert\Date(message: 'Use the YYYY-MM-DD format.')]
        public ?string $from = null,
        #[Assert\Date(message: 'Use the YYYY-MM-DD format.')]
        public ?string $to = null,
    ) {}
}
