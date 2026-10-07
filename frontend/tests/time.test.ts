import { describe, expect, it } from 'vitest';
import { formatWib, formatWibTime, relativeTime } from '@/lib/time';

describe('formatWib', () => {
  it('mengonversi UTC ke WIB lintas hari', () => {
    expect(formatWib('2026-09-08T17:00:00Z')).toBe('9 Sep 2026, 00.00 WIB');
  });

  it('menerima epoch milidetik', () => {
    expect(formatWib(Date.UTC(2026, 8, 8, 5, 20))).toBe('8 Sep 2026, 12.20 WIB');
  });

  it('mengembalikan tanda strip untuk null/invalid', () => {
    expect(formatWib(null)).toBe('—');
    expect(formatWib('bukan-tanggal')).toBe('—');
  });
});

describe('formatWibTime', () => {
  it('menampilkan jam WIB, bukan timezone mesin', () => {
    expect(formatWibTime('2026-09-08T03:42:00Z')).toBe('10.42');
  });
});

describe('relativeTime', () => {
  const now = new Date('2026-09-09T03:00:00Z');

  it('menit lalu', () => {
    expect(relativeTime('2026-09-09T02:57:00Z', now)).toBe('3 menit lalu');
  });

  it('jam lalu', () => {
    expect(relativeTime('2026-09-09T01:00:00Z', now)).toBe('2 jam lalu');
  });

  it('null berarti tidak pernah', () => {
    expect(relativeTime(null, now)).toBe('tidak pernah');
  });
});
