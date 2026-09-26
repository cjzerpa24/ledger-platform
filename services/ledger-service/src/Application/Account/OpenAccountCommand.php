<?php

declare(strict_types=1);

namespace App\Application\Account;

final readonly class OpenAccountCommand
{
    public function __construct(public string $name, public string $currency) {}
}
