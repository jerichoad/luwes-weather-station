import { AlertTriangle, ChevronRight, Droplets, MapPin, Thermometer } from 'lucide-react';
import Link from 'next/link';
import { ConnectivityBadge, StatusBadge } from '@/components/stations/Badges';
import type { OverviewStation } from '@/lib/api/types';
import { formatRelativeWithWib } from '@/lib/time';
import { cn } from '@/lib/utils';

function Reading({
  icon: Icon,
  label,
  value,
  unit,
}: {
  icon: typeof Thermometer;
  label: string;
  value: number | undefined;
  unit: string | undefined;
}) {
  return (
    <div className="rounded-md bg-muted/60 px-3 py-2.5">
      <p className="flex items-center gap-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
        <Icon aria-hidden="true" className="size-3" />
        {label}
      </p>
      <p className="mt-1 font-mono text-lg font-medium text-foreground tabular-nums">
        {value !== undefined ? value.toLocaleString('id-ID') : '—'}
        {value !== undefined && unit ? <span className="ml-1 text-xs text-muted-foreground">{unit}</span> : null}
      </p>
    </div>
  );
}

export function StationCard({ station, now }: { station: OverviewStation; now: Date }) {
  const temp = station.latest?.temp_air;
  const hum = station.latest?.humidity;
  const isOffline = station.connectivity === 'offline';
  const isNever = station.connectivity === 'never';

  return (
    <Link
      href={`/stations/${station.id}`}
      className={cn(
        'group flex flex-col gap-4 rounded-lg border bg-card p-5 shadow-sm transition-shadow hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
        isOffline && 'border-error/40 ring-1 ring-error/20',
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-foreground">{station.name}</p>
          <p className="mt-0.5 flex items-center gap-1 truncate text-xs text-muted-foreground">
            <span className="font-mono">{station.code}</span>
            {station.location ? (
              <>
                <span aria-hidden="true">·</span>
                <MapPin aria-hidden="true" className="size-3" />
                {station.location}
              </>
            ) : null}
          </p>
        </div>
        <ChevronRight aria-hidden="true" className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
      </div>

      <div className="flex flex-wrap gap-1.5">
        <StatusBadge status={station.status} />
        <ConnectivityBadge connectivity={station.connectivity} />
      </div>

      <div className="grid grid-cols-2 gap-3">
        <Reading icon={Thermometer} label="Suhu" value={temp?.value} unit={temp?.unit} />
        <Reading icon={Droplets} label="Kelembapan" value={hum?.value} unit={hum?.unit} />
      </div>

      {isOffline ? (
        <p className="flex items-center gap-1.5 rounded-md bg-error-tint px-2.5 py-1.5 text-xs font-medium text-error">
          <AlertTriangle aria-hidden="true" className="size-3.5 shrink-0" />
          Tidak mengirim data &gt; 15 menit
        </p>
      ) : null}

      <p className="text-xs text-muted-foreground">
        {isNever ? 'Belum pernah mengirim data' : `Diperbarui ${formatRelativeWithWib(station.last_seen_at, now)}`}
      </p>
    </Link>
  );
}
