import { ParseUUIDPipe, ValidationPipe } from '@nestjs/common';
import { RequestValidationFailed } from './problem-details.filter';

export const validationPipe = new ValidationPipe({
  whitelist: true,
  forbidNonWhitelisted: true,
  transform: true,
  exceptionFactory: (errors) => RequestValidationFailed.fromValidationErrors(errors),
});

export const uuidParam = (field: string) =>
  new ParseUUIDPipe({
    exceptionFactory: () => new RequestValidationFailed([{ field, message: `${field} must be a UUID` }]),
  });
