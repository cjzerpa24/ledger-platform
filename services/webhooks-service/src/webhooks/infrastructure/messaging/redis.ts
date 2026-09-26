import Redis from 'ioredis';

export const REDIS = Symbol('Redis');

export const createRedis = (url: string): Redis => new Redis(url, { maxRetriesPerRequest: null, lazyConnect: false });
