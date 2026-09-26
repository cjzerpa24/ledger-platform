import { Module } from '@nestjs/common';
import { LoggerModule } from 'nestjs-pino';
import { APP_CONFIG, AppConfig } from './shared/config/app-config';
import { ConfigModule } from './shared/config/config.module';
import { WebhooksModule } from './webhooks/webhooks.module';

@Module({
  imports: [
    ConfigModule,
    LoggerModule.forRootAsync({
      inject: [APP_CONFIG],
      useFactory: (config: AppConfig) => ({
        pinoHttp: { level: config.logLevel, autoLogging: { ignore: (req) => req.url === '/health' } },
      }),
    }),
    WebhooksModule,
  ],
})
export class AppModule {}
