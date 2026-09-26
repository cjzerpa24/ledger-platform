<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\ProblemDetails;

use App\Domain\Account\Exception\AccountNotFound;
use App\Domain\Shared\LedgerException;
use App\Domain\Transfer\Exception\IdempotencyConflict;
use App\Domain\Transfer\Exception\TransferNotFound;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders every error as RFC 9457 problem+json. Domain exceptions map to a
 * status here, so the domain stays free of HTTP concerns.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ProblemDetailsListener
{
    private const string TYPE_PREFIX = 'urn:ledger:problem:';

    /** Domain exceptions not listed here are business-rule violations: 422. */
    private const array DOMAIN_STATUS = [
        AccountNotFound::class => Response::HTTP_NOT_FOUND,
        TransferNotFound::class => Response::HTTP_NOT_FOUND,
        IdempotencyConflict::class => Response::HTTP_CONFLICT,
    ];

    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        $problem = match (true) {
            $exception instanceof LedgerException => $this->fromDomain($exception),
            $exception instanceof HttpExceptionInterface => $this->fromHttp($exception),
            default => $this->fromUnexpected($exception),
        };
        $problem['instance'] = $event->getRequest()->getPathInfo();

        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
        $headers['Content-Type'] = 'application/problem+json';

        $event->setResponse(new JsonResponse($problem, $problem['status'], $headers));
    }

    /** @return array{type: string, title: string, status: int, detail: string} */
    private function fromDomain(LedgerException $exception): array
    {
        $name = new \ReflectionClass($exception)->getShortName();

        return [
            'type' => self::TYPE_PREFIX . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name)),
            'title' => (string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name),
            'status' => self::DOMAIN_STATUS[$exception::class] ?? Response::HTTP_UNPROCESSABLE_ENTITY,
            'detail' => $exception->getMessage(),
        ];
    }

    /** @return array{type: string, title: string, status: int, detail: string, violations?: list<array{field: string, message: string}>} */
    private function fromHttp(HttpExceptionInterface $exception): array
    {
        $status = $exception->getStatusCode();
        $previous = $exception->getPrevious();

        if ($previous instanceof ValidationFailedException) {
            $violations = [];
            foreach ($previous->getViolations() as $violation) {
                $violations[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
            }

            return [
                'type' => self::TYPE_PREFIX . 'validation-failed',
                'title' => 'Validation Failed',
                'status' => $status,
                'detail' => 'The request contains invalid fields.',
                'violations' => $violations,
            ];
        }

        return [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $exception->getMessage(),
        ];
    }

    /** @return array{type: string, title: string, status: int, detail: string} */
    private function fromUnexpected(\Throwable $exception): array
    {
        $this->logger->error('Unhandled exception: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

        return [
            'type' => 'about:blank',
            'title' => 'Internal Server Error',
            'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
            'detail' => $this->debug ? $exception->getMessage() : 'An unexpected error occurred.',
        ];
    }
}
