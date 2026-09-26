import { ArgumentsHost, Catch, ExceptionFilter, HttpException, HttpStatus, Logger } from '@nestjs/common';
import { ValidationError } from 'class-validator';
import { Response } from 'express';
import { DeliveryNotFound, DeliveryNotRetryable } from '../../domain/delivery/errors';
import { SubscriptionNotFound } from '../../domain/subscription/errors';
import { WebhooksError } from '../../domain/shared/webhooks-error';

const TYPE_PREFIX = 'urn:webhooks:problem:';

export interface FieldError {
  field: string;
  message: string;
}

/** Thrown by the ValidationPipe and the id pipes; rendered as 422 `validation-failed`. */
export class RequestValidationFailed extends Error {
  constructor(readonly errors: FieldError[]) {
    super('The request is invalid.');
  }

  static fromValidationErrors(errors: ValidationError[], parent = ''): RequestValidationFailed {
    const flatten = (list: ValidationError[], prefix: string): FieldError[] =>
      list.flatMap((error) => {
        const field = prefix === '' ? error.property : `${prefix}.${error.property}`;
        return [
          ...Object.values(error.constraints ?? {}).map((message) => ({ field, message })),
          ...flatten(error.children ?? [], field),
        ];
      });

    return new RequestValidationFailed(flatten(errors, parent));
  }
}

/** Domain errors not listed here are business-rule violations: 422. */
const DOMAIN_STATUS = new Map<Function, number>([
  [SubscriptionNotFound, HttpStatus.NOT_FOUND],
  [DeliveryNotFound, HttpStatus.NOT_FOUND],
  [DeliveryNotRetryable, HttpStatus.CONFLICT],
]);

const HTTP_TYPES: Record<number, string> = { 400: 'malformed-request', 404: 'not-found', 405: 'method-not-allowed' };

/** Renders every error as RFC 9457 application/problem+json. */
@Catch()
export class ProblemDetailsFilter implements ExceptionFilter {
  private readonly logger = new Logger(ProblemDetailsFilter.name);

  catch(exception: unknown, host: ArgumentsHost): void {
    const response = host.switchToHttp().getResponse<Response>();
    const problem = this.toProblem(exception);
    response.status(problem.status).type('application/problem+json').json(problem);
  }

  private toProblem(exception: unknown): Record<string, unknown> & { status: number } {
    if (exception instanceof RequestValidationFailed) {
      return problem(422, 'validation-failed', 'Validation failed', exception.message, { errors: exception.errors });
    }
    if (exception instanceof WebhooksError) {
      const status = DOMAIN_STATUS.get(exception.constructor) ?? HttpStatus.UNPROCESSABLE_ENTITY;
      return problem(status, exception.code, title(exception.code), exception.message);
    }
    if (exception instanceof HttpException) {
      const status = exception.getStatus();
      const code = HTTP_TYPES[status] ?? 'http-error';
      return problem(status, code, title(code), exception.message);
    }
    this.logger.error(exception instanceof Error ? (exception.stack ?? exception.message) : String(exception));

    return problem(500, 'internal-error', 'Internal error', 'An unexpected error occurred.');
  }
}

function problem(status: number, code: string, titleText: string, detail: string, extra: object = {}) {
  return { type: TYPE_PREFIX + code, title: titleText, status, detail, ...extra };
}

function title(code: string): string {
  const words = code.split('-').join(' ');
  return words.charAt(0).toUpperCase() + words.slice(1);
}
