import { Type } from 'class-transformer';
import {
  ArrayMaxSize,
  ArrayNotEmpty,
  IsArray,
  IsIn,
  IsInt,
  IsOptional,
  IsString,
  IsUUID,
  Max,
  MaxLength,
  Min,
} from 'class-validator';
import { DELIVERY_STATUSES } from '../../domain/delivery/delivery';
import type { DeliveryStatus } from '../../domain/delivery/delivery';

export class CreateSubscriptionDto {
  @IsString()
  @MaxLength(2048)
  url!: string;

  @IsArray()
  @ArrayNotEmpty()
  @ArrayMaxSize(20)
  @IsString({ each: true })
  eventTypes!: string[];

  @IsOptional()
  @IsString()
  @MaxLength(255)
  description?: string;
}

export class ListDeliveriesQuery {
  @IsOptional()
  @IsIn(DELIVERY_STATUSES)
  status?: DeliveryStatus;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(100)
  limit: number = 20;

  @IsOptional()
  @IsUUID()
  cursor?: string;
}
