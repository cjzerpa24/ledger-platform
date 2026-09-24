<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Transfer\TransferRepository;
use Symfony\Component\Uid\Uuid;

final class GetTransferHandler
{
    public function __construct(private readonly TransferRepository $transfers) {}

    public function handle(Uuid $id): TransferView
    {
        return TransferView::fromTransfer($this->transfers->get($id));
    }
}
