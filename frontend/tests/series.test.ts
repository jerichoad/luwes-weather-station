import { describe, expect, it } from 'vitest';
import { insertGaps } from '@/lib/series';

const MIN = 60_000;
const T0 = Date.UTC(2026, 8, 8, 0, 0);

describe('insertGaps', () => {
  it('tanpa gap: tidak ada titik null yang disisipkan', () => {
    const ts = [T0, T0 + MIN, T0 + 2 * MIN];
    const res = insertGaps(ts, [1, 2, 3], 60);
    expect(res.timestamps).toEqual(ts);
    expect(res.values).toEqual([1, 2, 3]);
    expect(res.gaps).toEqual([]);
  });

  it('gap 3 jam pada interval 1m: satu null disisipkan', () => {
    const ts = [T0, T0 + MIN, T0 + MIN + 180 * MIN, T0 + 182 * MIN];
    const res = insertGaps(ts, [20, 21, 22, 23], 60);
    expect(res.values).toEqual([20, 21, null, 22, 23]);
    expect(res.timestamps).toHaveLength(5);
    expect(res.gaps).toEqual([{ start: T0 + MIN, end: T0 + 181 * MIN }]);
  });

  it('jarak tepat 1,5× interval belum dianggap gap', () => {
    const ts = [T0, T0 + 90_000];
    expect(insertGaps(ts, [1, 2], 60).values).toEqual([1, 2]);
  });

  it('gap di awal/akhir rentang tidak ditambahkan', () => {
    const ts = [T0 + 60 * MIN, T0 + 61 * MIN];
    const res = insertGaps(ts, [5, 6], 60);
    expect(res.timestamps[0]).toBe(T0 + 60 * MIN);
    expect(res.timestamps.at(-1)).toBe(T0 + 61 * MIN);
    expect(res.values).toEqual([5, 6]);
  });

  it('nilai null asli dari backend dipertahankan', () => {
    const ts = [T0, T0 + MIN, T0 + 2 * MIN];
    expect(insertGaps(ts, [1, null, 3], 60).values).toEqual([1, null, 3]);
  });

  it('melempar error jika panjang array berbeda', () => {
    expect(() => insertGaps([T0], [1, 2], 60)).toThrow();
  });
});
