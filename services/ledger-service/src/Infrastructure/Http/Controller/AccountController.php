<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Account\GetAccountHandler;
use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Infrastructure\Http\Request\OpenAccountRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/accounts')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly OpenAccountHandler $openAccount,
        private readonly GetAccountHandler $getAccount,
    ) {}

    #[Route('', name: 'account_open', methods: ['POST'])]
    public function open(#[MapRequestPayload] OpenAccountRequest $request): JsonResponse
    {
        $view = $this->openAccount->handle(new OpenAccountCommand($request->name, $request->currency));

        return $this->json($view, Response::HTTP_CREATED, [
            'Location' => $this->generateUrl('account_show', ['id' => $view->id]),
        ]);
    }

    #[Route('/{id}', name: 'account_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(Uuid $id): JsonResponse
    {
        return $this->json($this->getAccount->handle($id));
    }
}
