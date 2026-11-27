import { describe, expect, it } from 'vitest';
import { moduleId } from '../src/lib/modules/gdpr';

describe('gdpr module', () => {
  it('exports its identifier', () => {
    expect(moduleId).toBe('gdpr');
  });
});
