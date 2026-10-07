import { describe, expect, it } from 'vitest';
import { rainRangeToQuery, rangeToQuery } from '@/lib/ranges';

const REF = new Date('2026-09-09T03:42:37.512Z');

describe('rangeToQuery', () => {
  it('24h → interval 1m, dibulatkan ke awal menit', () => {
    const q = rangeToQuery('24h', REF);
    expect(q.interval).toBe('1m');
    expect(q.to).toBe('2026-09-09T03:42:00.000Z');
    expect(q.from).toBe('2026-09-08T03:42:00.000Z');
    expect(q.points).toBe(1440);
  });

  it('7d → interval 1h, dibulatkan ke awal jam', () => {
    const q = rangeToQuery('7d', REF);
    expect(q.interval).toBe('1h');
    expect(q.to).toBe('2026-09-09T03:00:00.000Z');
    expect(q.from).toBe('2026-09-02T03:00:00.000Z');
    expect(q.points).toBe(168);
  });

  it('30d → interval 1h', () => {
    const q = rangeToQuery('30d', REF);
    expect(q.interval).toBe('1h');
    expect(q.to).toBe('2026-09-09T03:00:00.000Z');
    expect(q.from).toBe('2026-08-10T03:00:00.000Z');
    expect(q.points).toBe(720);
  });

  it('referensi berbeda dalam menit yang sama menghasilkan query identik (cache stabil)', () => {
    const a = rangeToQuery('24h', new Date('2026-09-09T03:42:01Z'));
    const b = rangeToQuery('24h', new Date('2026-09-09T03:42:59Z'));
    expect(a).toEqual(b);
  });
});

describe('rainRangeToQuery', () => {
  it('per jam 24h → 24 bar, termasuk jam berjalan', () => {
    const q = rainRangeToQuery('24h', '1h', REF);
    expect(q.interval).toBe('1h');
    expect(q.to).toBe('2026-09-09T04:00:00.000Z');
    expect(q.from).toBe('2026-09-08T04:00:00.000Z');
    expect(q.points).toBe(24);
  });

  it('per hari 30d → 30 bar', () => {
    const q = rainRangeToQuery('30d', '1d', REF);
    expect(q.interval).toBe('1d');
    expect(q.points).toBe(30);
  });
});
