<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Resolves an IdempotencyKey action argument from the required request header. */
final class IdempotencyKeyValueResolver implements ValueResolverInterface
{
    public const string HEADER = 'Idempotency-Key';
    private const int MAX_LENGTH = 255;

    /** @return iterable<IdempotencyKey> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (IdempotencyKey::class !== $argument->getType()) {
            return [];
        }

        $value = trim((string) $request->headers->get(self::HEADER, ''));
        if ('' === $value || mb_strlen($value) > self::MAX_LENGTH) {
            throw new BadRequestHttpException(\sprintf('The "%s" header is required and must be 1-%d characters.', self::HEADER, self::MAX_LENGTH));
        }

        return [new IdempotencyKey($value)];
    }
}
