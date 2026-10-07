export type PresetRange = '24h' | '7d' | '30d';

export interface RangeQuery {
  from: string; // ISO 8601 UTC
  to: string; // ISO 8601 UTC
  interval: '1m' | '1h' | '1d';
  points: number;
}

/**
 * Mapping rentang → interval sesuai spec Bagian 6 FRONTEND.md:
 * - 24 jam  → 1m (1.440 titik)
 * - 7 hari  → 1h (168 titik)
 * - 30 hari → 1h (720 titik)
 *
 * Dibulatkan ke batas interval (awal menit/jam) agar cache TanStack Query efektif
 * dan titik tidak bergeser setiap render/polling.
 */
export function rangeToQuery(preset: PresetRange, referenceTime: Date = new Date()): RangeQuery {
  const refMs = referenceTime.getTime();

  if (preset === '24h') {
    // Dibulatkan ke awal menit UTC
    const toMs = Math.floor(refMs / 60_000) * 60_000;
    const fromMs = toMs - 24 * 3600 * 1000;
    return {
      from: new Date(fromMs).toISOString(),
      to: new Date(toMs).toISOString(),
      interval: '1m',
      points: 1440,
    };
  }

  if (preset === '7d') {
    // Dibulatkan ke awal jam UTC
    const toMs = Math.floor(refMs / 3_600_000) * 3_600_000;
    const fromMs = toMs - 7 * 24 * 3600 * 1000;
    return {
      from: new Date(fromMs).toISOString(),
      to: new Date(toMs).toISOString(),
      interval: '1h',
      points: 168,
    };
  }

  // 30d
  const toMs = Math.floor(refMs / 3_600_000) * 3_600_000;
  const fromMs = toMs - 30 * 24 * 3600 * 1000;
  return {
    from: new Date(fromMs).toISOString(),
    to: new Date(toMs).toISOString(),
    interval: '1h',
    points: 720,
  };
}

const PRESET_HOURS: Record<PresetRange, number> = { '24h': 24, '7d': 168, '30d': 720 };

/**
 * Hujan: rentang mengikuti pemilih rentang, interval dari toggle.
 * - per jam (1h) → 24 / 168 / 720 bar
 * - per hari (1d) → 1 / 7 / 30 bar (hari kalender WIB, dihitung backend)
 * `to` = awal jam berikutnya agar bucket jam berjalan ikut tampil.
 */
export function rainRangeToQuery(
  preset: PresetRange,
  interval: '1h' | '1d',
  referenceTime: Date = new Date(),
): RangeQuery {
  const hours = PRESET_HOURS[preset];
  const toMs = Math.floor(referenceTime.getTime() / 3_600_000) * 3_600_000 + 3_600_000;
  const fromMs = toMs - hours * 3_600_000;
  return {
    from: new Date(fromMs).toISOString(),
    to: new Date(toMs).toISOString(),
    interval,
    points: interval === '1h' ? hours : Math.round(hours / 24),
  };
}

export const INTERVAL_SECONDS: Record<string, number> = { raw: 60, '1m': 60, '1h': 3600, '1d': 86400 };
