import type { ConsentPermit, Scope } from './consent-client';

/** Optional consumer owns all its side effects; null must stop them synchronously. */
export interface ConsentConsumer {
  setPermit(permit: ConsentPermit | null): void;
  dispose(): void;
}
export type ConsentConsumerFactory = (scope: Scope, apiOrigin: string) => ConsentConsumer;

/** No browser globals or third-party imports in this bridge. */
export class ConsentConsumerBridge {
  private consumer: ConsentConsumer | null = null;
  private permit: ConsentPermit | null = null;
  private disposed = false;

  attach(factory: ConsentConsumerFactory | undefined, scope: Scope, apiOrigin: string): void {
    if (this.disposed || this.consumer || !factory) return;
    try {
      const consumer = factory(scope, apiOrigin);
      this.consumer = consumer;
      consumer.setPermit(this.permit);
    } catch {
      this.dispose();
    }
  }

  publish(value: ConsentPermit | null): void {
    if (this.disposed) return;
    this.permit = value;
    try {
      this.consumer?.setPermit(value);
    } catch {
      this.dispose();
    }
  }

  dispose(): void {
    if (this.disposed) return;
    this.disposed = true;
    this.permit = null;
    const consumer = this.consumer;
    this.consumer = null;
    try { consumer?.setPermit(null); } catch { /* consumer must fail closed */ }
    try { consumer?.dispose(); } catch { /* never prevent the consent UI */ }
  }
}
