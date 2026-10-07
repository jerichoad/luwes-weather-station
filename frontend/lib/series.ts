/**
 * Sisipkan titik `null` di antara dua timestamp berurutan jika jaraknya
 * > 1,5 × interval_seconds, supaya ECharts (connectNulls:false) merender
 * garis putus alih-alih menyambung lurus melewati gap data.
 *
 * Gap di awal/akhir array tidak ditambahkan (tidak ada "titik sebelumnya"
 * untuk dibandingkan).
 *
 * @param timestamps epoch milidetik, terurut naik
 * @param values nilai sejajar dengan timestamps (null = tidak ada data)
 * @param intervalSeconds interval yang diharapkan antar titik
 */
export function insertGaps(
  timestamps: number[],
  values: (number | null)[],
  intervalSeconds: number,
): { timestamps: number[]; values: (number | null)[]; gaps: Array<{ start: number; end: number }> } {
  if (timestamps.length !== values.length) {
    throw new Error('timestamps dan values harus sama panjang');
  }
  if (timestamps.length < 2) {
    return { timestamps: [...timestamps], values: [...values], gaps: [] };
  }

  const thresholdMs = intervalSeconds * 1000 * 1.5;
  const outTs: number[] = [timestamps[0]];
  const outVal: (number | null)[] = [values[0]];
  const gaps: Array<{ start: number; end: number }> = [];

  for (let i = 1; i < timestamps.length; i++) {
    const prevTs = timestamps[i - 1];
    const curTs = timestamps[i];
    const diff = curTs - prevTs;

    if (diff > thresholdMs) {
      // sisipkan satu titik null di tengah gap agar garis putus
      const midTs = prevTs + Math.floor(diff / 2);
      outTs.push(midTs);
      outVal.push(null);
      gaps.push({ start: prevTs, end: curTs });
    }

    outTs.push(curTs);
    outVal.push(values[i]);
  }

  return { timestamps: outTs, values: outVal, gaps };
}

export interface EchartsPoint {
  value: [number, number | null];
}

export function toEchartsDataset(timestamps: number[], values: (number | null)[]): EchartsPoint[] {
  return timestamps.map((t, i) => ({ value: [t, values[i]] }));
}
