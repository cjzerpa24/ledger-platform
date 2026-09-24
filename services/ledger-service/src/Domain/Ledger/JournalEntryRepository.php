<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

interface JournalEntryRepository
{
    public function add(JournalEntry $entry): void;
}
