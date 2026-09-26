import { INestApplication } from '@nestjs/common';
import { ProblemDetailsFilter } from '../webhooks/infrastructure/http/problem-details.filter';
import { validationPipe } from '../webhooks/infrastructure/http/pipes';

/** Shared by main.ts and the e2e tests, so tests exercise the same HTTP behaviour as production. */
export function configureHttp(app: INestApplication): void {
  app.useGlobalPipes(validationPipe);
  app.useGlobalFilters(new ProblemDetailsFilter());
}
