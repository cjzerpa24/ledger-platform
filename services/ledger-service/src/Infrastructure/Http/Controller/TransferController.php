<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Transfer\TransferMoneyDTO;

class TransferController
{
    public function __construct(private TransferMoneyHandler $transferMoneyHandler)
    {
    }

    #[Route('/transfer', name: 'transfer_money', methods: ['POST'])]
    public function transferMoney(TransferMoneyDTO $transferMoneyDTO): JsonResponse
    {
        $transferViewDTO = $this->transferMoneyHandler->handle($transferMoneyDTO);
        return new JsonResponse(['message' => 'Transfer money successful', 'transfer' => $transferViewDTO]);
    }
}
