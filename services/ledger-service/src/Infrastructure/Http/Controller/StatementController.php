<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Statement\GetAccountStatementHandler;
use App\Application\Statement\StatementQuery;
use App\Infrastructure\Http\Request\StatementRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

final class StatementController extends AbstractController
{
    public function __construct(private readonly GetAccountStatementHandler $getStatement) {}

    #[Route('/accounts/{id}/statement', name: 'account_statement', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(Uuid $id, #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ?StatementRequest $query = null): JsonResponse
    {
        return $this->json($this->getStatement->handle(new StatementQuery(
            $id,
            self::date($query?->from),
            self::date($query?->to),
        )));
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable($value . ' 00:00:00', new \DateTimeZone('UTC'));
    }
}
