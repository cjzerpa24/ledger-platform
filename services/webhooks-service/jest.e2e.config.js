/** Integration and e2e tests: need the compose Postgres and Redis (TEST_DATABASE_URL, TEST_REDIS_URL). */
module.exports = {
  moduleFileExtensions: ['js', 'json', 'ts'],
  rootDir: '.',
  roots: ['<rootDir>/test/integration', '<rootDir>/test/e2e'],
  testRegex: '.*\\.spec\\.ts$',
  transform: { '^.+\\.ts$': 'ts-jest' },
  moduleNameMapper: { '^(\\.{1,2}/.*)\\.js$': '$1' },
  testEnvironment: 'node',
  testTimeout: 30000,
};
