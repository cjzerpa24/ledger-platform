<?php

declare(strict_types=1);

namespace App\Application\Port;

interface TransactionManager
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
}
