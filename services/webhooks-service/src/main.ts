import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import { Logger } from 'nestjs-pino';
import { AppModule } from './app.module';
import { APP_CONFIG, AppConfig } from './shared/config/app-config';
import { configureHttp } from './shared/configure-http';

async function bootstrap(): Promise<void> {
  const app = await NestFactory.create(AppModule, { bufferLogs: true });
  app.useLogger(app.get(Logger));
  app.enableShutdownHooks();
  configureHttp(app);

  const config = app.get<AppConfig>(APP_CONFIG);
  if (config.roles.includes('api')) {
    await app.listen(config.port);
  } else {
    await app.init();
  }
}

void bootstrap();
