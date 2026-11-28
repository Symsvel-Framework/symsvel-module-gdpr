import type { ConsentConsumerFactory } from '../gdpr/consent-consumer';

// CI-only consumer: observes the bridge without loading any external service.
export const createConsentConsumer: ConsentConsumerFactory = () => ({
  setPermit() {},
  routeChanged(id, url) {
    document.documentElement.dataset.gdprFixtureRoute = id ?? '';
    document.documentElement.dataset.gdprFixturePath = url.pathname;
  },
  dispose() {
    delete document.documentElement.dataset.gdprFixtureRoute;
    delete document.documentElement.dataset.gdprFixturePath;
  }
});
