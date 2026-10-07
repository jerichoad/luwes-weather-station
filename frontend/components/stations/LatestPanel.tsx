import { AlertTriangle, CloudRain, Compass, Droplets, Gauge, Sun, Thermometer, Wind } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { LatestReading } from '@/lib/api/types';
import { formatWibTime, relativeTime } from '@/lib/time';
import { cn } from '@/lib/utils';

const ICONS: Record<string, LucideIcon> = {
  temp_air: Thermometer,
  humidity: Droplets,
  pressure: Gauge,
  wind_speed: Wind,
  wind_dir: Compass,
  rain_counter: CloudRain,
  solar_rad: Sun,
};

const STALE_MS = 15 * 60 * 1000;

function directionLabel(deg: number): string {
  const dirs = ['U', 'UTL', 'TL', 'TTL', 'T', 'TMg', 'Mg', 'SMg', 'S', 'SBD', 'BD', 'BBD', 'B', 'BBL', 'BL', 'UBL'];
  return dirs[Math.round(((deg % 360) + 360) % 360 / 22.5) % 16];
}

export function LatestPanel({
  readings,
  channelsWithoutData,
  now,
}: {
  readings: LatestReading[];
  channelsWithoutData: string[];
  now: Date;
}) {
  return (
    <ul role="list" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {readings.map((r) => {
        const Icon = ICONS[r.sensor_type] ?? Gauge;
        const stale =
          channelsWithoutData.includes(r.channel_key) ||
          r.time === null ||
          now.getTime() - new Date(r.time).getTime() > STALE_MS;
        const isRain = r.sensor_type === 'rain_counter';
        const isDir = r.sensor_type === 'wind_dir';

        const displayValue = isRain ? r.rain_today_mm ?? null : r.value;
        const displayUnit = isRain ? r.rain_today_unit ?? 'mm' : r.unit;

        return (
          <li
            key={r.channel_id}
            className={cn('flex flex-col gap-2 rounded-lg border bg-card p-4', stale && 'border-warning/40 bg-warning-tint/30')}
          >
            <div className="flex items-start justify-between gap-2">
              <p className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
                <Icon aria-hidden="true" className="size-3.5" />
                {r.label ?? r.channel_key}
              </p>
              {stale ? (
                <span
                  title="Tidak ada data valid dalam 15 menit terakhir"
                  className="inline-flex items-center gap-1 rounded-full bg-warning-tint px-1.5 py-0.5 text-[10px] font-semibold text-warning"
                >
                  <AlertTriangle aria-hidden="true" className="size-3" />
                  &gt;15m
                </span>
              ) : null}
            </div>
            <p className="font-mono text-2xl font-medium text-foreground tabular-nums">
              {displayValue !== null && displayValue !== undefined ? displayValue.toLocaleString('id-ID') : '—'}
              <span className="ml-1 text-sm text-muted-foreground">{displayUnit}</span>
              {isDir && r.value !== null ? (
                <span className="ml-2 text-sm font-semibold text-navy-600">{directionLabel(r.value)}</span>
              ) : null}
            </p>
            <p className="text-[11px] text-muted-foreground">
              {isRain ? 'Total hari ini (WIB) · ' : ''}
              {r.time ? `${relativeTime(r.time, now)} · ${formatWibTime(r.time)} WIB` : 'Belum ada data'}
            </p>
            {r.sensor ? <p className="font-mono text-[10px] text-muted-foreground/80">Sensor {r.sensor.serial_number}</p> : null}
          </li>
        );
      })}
    </ul>
  );
}
