<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Transfer\GetTransferHandler;
use App\Application\Transfer\PostTransferResult;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Application\Transfer\RecordWithdrawalCommand;
use App\Application\Transfer\RecordWithdrawalHandler;
use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use App\Infrastructure\Http\Request\FundsRequest;
use App\Infrastructure\Http\Request\IdempotencyKey;
use App\Infrastructure\Http\Request\TransferRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

final class TransferController extends AbstractController
{
    public function __construct(
        private readonly TransferMoneyHandler $transferMoney,
        private readonly RecordDepositHandler $recordDeposit,
        private readonly RecordWithdrawalHandler $recordWithdrawal,
        private readonly GetTransferHandler $getTransfer,
    ) {}

    #[Route('/transfers', name: 'transfer_create', methods: ['POST'])]
    public function transfer(#[MapRequestPayload] TransferRequest $request, IdempotencyKey $key): JsonResponse
    {
        return $this->respond($this->transferMoney->handle(new TransferMoneyCommand(
            Uuid::fromString($request->sourceAccountId),
            Uuid::fromString($request->destinationAccountId),
            $request->amount,
            $request->description,
            $key->value,
        )));
    }

    #[Route('/accounts/{id}/deposits', name: 'account_deposit', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function deposit(Uuid $id, #[MapRequestPayload] FundsRequest $request, IdempotencyKey $key): JsonResponse
    {
        return $this->respond($this->recordDeposit->handle(
            new RecordDepositCommand($id, $request->amount, $request->description, $key->value),
        ));
    }

    #[Route('/accounts/{id}/withdrawals', name: 'account_withdrawal', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function withdraw(Uuid $id, #[MapRequestPayload] FundsRequest $request, IdempotencyKey $key): JsonResponse
    {
        return $this->respond($this->recordWithdrawal->handle(
            new RecordWithdrawalCommand($id, $request->amount, $request->description, $key->value),
        ));
    }

    #[Route('/transfers/{id}', name: 'transfer_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(Uuid $id): JsonResponse
    {
        return $this->json($this->getTransfer->handle($id));
    }

    private function respond(PostTransferResult $result): JsonResponse
    {
        // Location only on 201: PHP's SAPI turns a 200 carrying Location into a 302.
        if ($result->replayed) {
            return $this->json($result->transfer, Response::HTTP_OK, ['Idempotent-Replayed' => 'true']);
        }

        return $this->json($result->transfer, Response::HTTP_CREATED, [
            'Location' => $this->generateUrl('transfer_show', ['id' => $result->transfer->id]),
        ]);
    }
}
