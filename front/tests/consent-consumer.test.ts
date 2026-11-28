import { describe, expect, it, vi } from 'vitest';
import { ConsentConsumerBridge } from './consent-consumer';
import type { ConsentPermit } from './consent-client';

const permit: ConsentPermit = { credential: 'a'.repeat(64) + '.' + 'b'.repeat(64), version: '1'.repeat(32), expiresAt: Date.now() + 60000 };

describe('optional consent consumer', () => {
  it('works without the optional module', () => {
    const bridge = new ConsentConsumerBridge();
    expect(() => {
      bridge.attach(undefined, 'front', 'https://api.example.test');
      bridge.publish(permit);
      bridge.publish(null);
      bridge.dispose();
    }).not.toThrow();
  });
  it('passes the current permit and synchronously forwards withdrawal', () => {
    const bridge = new ConsentConsumerBridge();
    const consumer = { setPermit: vi.fn(), dispose: vi.fn() };
    const factory = vi.fn(() => consumer);
    bridge.publish(permit);
    bridge.attach(factory, 'front', 'https://api.example.test');
    expect(factory).toHaveBeenCalledWith('front', 'https://api.example.test');
    expect(consumer.setPermit).toHaveBeenLastCalledWith(permit);
    bridge.publish(null);
    expect(consumer.setPermit).toHaveBeenLastCalledWith(null);
    bridge.dispose();
    expect(consumer.dispose).toHaveBeenCalledOnce();
    bridge.publish(permit);
    expect(consumer.setPermit).toHaveBeenLastCalledWith(null);
  });
  it('disposes a broken consumer without breaking the consent UI', () => {
    const bridge = new ConsentConsumerBridge();
    const consumer = { setPermit: vi.fn(() => { throw new Error('Failure'); }), dispose: vi.fn() };
    expect(() => bridge.attach(() => consumer, 'app', 'https://api.example.test')).not.toThrow();
    expect(consumer.dispose).toHaveBeenCalledOnce();
    bridge.publish(permit);
    expect(consumer.setPermit).toHaveBeenCalledTimes(2);
  });
});
