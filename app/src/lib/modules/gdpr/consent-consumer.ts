import type { ConsentPermit, Scope } from './consent-client';

/** Optional consumer owns all its side effects; null must stop them synchronously. */
export interface ConsentConsumer {
  setPermit(permit: ConsentPermit | null): void;
  dispose(): void;
  routeChanged?(routeId: string | null, url: URL): void;
  requiresDocumentNavigation?(): boolean;
  selectionCommitted?(): void;
}
export type ConsentConsumerFactory = (scope: Scope, apiOrigin: string) => ConsentConsumer;

/** No browser globals or third-party imports in this bridge. */
export class ConsentConsumerBridge {
  private consumer: ConsentConsumer | null = null;
  private permit: ConsentPermit | null = null;
  private disposed = false;
  private route: { id: string | null; url: URL } | null = null;

  attach(factory: ConsentConsumerFactory | undefined, scope: Scope, apiOrigin: string): void {
    if (this.disposed || this.consumer || !factory) return;
    try {
      const consumer = factory(scope, apiOrigin);
      this.consumer = consumer;
      if (this.route) consumer.routeChanged?.(this.route.id, this.route.url);
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

  routeChanged(id: string | null, url: URL): void {
    if (this.disposed) return;
    this.route = { id, url: new URL(url.href) };
    try { this.consumer?.routeChanged?.(id, this.route.url); } catch { this.dispose(); }
  }

  requiresDocumentNavigation(): boolean {
    try { return this.consumer?.requiresDocumentNavigation?.() === true; } catch { this.dispose(); return true; }
  }

  selectionCommitted(): void {
    if (this.disposed) return;
    try { this.consumer?.selectionCommitted?.(); } catch { this.dispose(); }
  }

  dispose(): void {
    if (this.disposed) return;
    this.disposed = true;
    this.permit = null;
    this.route = null;
    const consumer = this.consumer;
    this.consumer = null;
    try { consumer?.setPermit(null); } catch { /* consumer must fail closed */ }
    try { consumer?.dispose(); } catch { /* never prevent the consent UI */ }
  }
}
