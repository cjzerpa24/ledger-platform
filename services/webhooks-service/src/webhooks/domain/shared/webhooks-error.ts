/** Base class for every domain rule violation. Carries a stable code, never an HTTP status. */
export abstract class WebhooksError extends Error {
  abstract readonly code: string;

  constructor(message: string) {
    super(message);
    this.name = new.target.name;
  }
}
