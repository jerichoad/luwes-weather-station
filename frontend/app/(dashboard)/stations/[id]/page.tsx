'use client';

import { Battery, Clock, Cpu, MapPin, Radio, SignalHigh } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';
import { RainChart } from '@/components/charts/RainChart';
import { RangePicker } from '@/components/charts/RangePicker';
import { TempHumidityChart } from '@/components/charts/TempHumidityChart';
import { WindChart } from '@/components/charts/WindChart';
import { BatteryTrendChart } from '@/components/charts/BatteryTrendChart';
import { SectionCard } from '@/components/dashboard/components';
import { usePollInterval } from '@/components/refresh-context';
import { ConnectivityBadge, StatusBadge } from '@/components/stations/Badges';
import { LatestPanel } from '@/components/stations/LatestPanel';
import { ChartSkeleton, LoadingState } from '@/components/states/LoadingState';
import { EmptyState } from '@/components/states/EmptyState';
import { ErrorState } from '@/components/states/ErrorState';
import { SegmentedControl } from '@/components/ui';
import { useDevice, useDeviceHealth, useDeviceLatest, useReadings } from '@/lib/api/hooks';
import { rainRangeToQuery, rangeToQuery, type PresetRange } from '@/lib/ranges';
import { formatWib, relativeTime } from '@/lib/time';

export default function StationDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = React.use(params);
  const deviceId = Number(id);

  const pollLatestMs = usePollInterval(30_000);
  const pollChartMs = usePollInterval(60_000);

  const [now, setNow] = React.useState(() => new Date());
  React.useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 15_000);
    return () => clearInterval(t);
  }, []);

  const [range, setRange] = React.useState<PresetRange>('24h');
  const [rainInterval, setRainInterval] = React.useState<'1h' | '1d'>('1h');

  const deviceQuery = useDevice(deviceId);
  const latestQuery = useDeviceLatest(deviceId, pollLatestMs);
  const healthQuery = useDeviceHealth(deviceId, pollLatestMs);

  const rangeQuery = React.useMemo(() => rangeToQuery(range, now), [range, now]);
  const rainQuery = React.useMemo(() => rainRangeToQuery(range, rainInterval, now), [range, rainInterval, now]);

  const tempHumidReadings = useReadings(
    { device_id: deviceId, sensor_type: 'temp_air,humidity', from: rangeQuery.from, to: rangeQuery.to, interval: rangeQuery.interval },
    pollChartMs,
  );
  const rainReadings = useReadings(
    { device_id: deviceId, sensor_type: 'rain_counter', from: rainQuery.from, to: rainQuery.to, interval: rainQuery.interval, agg: 'sum' },
    pollChartMs,
  );
  const windReadings = useReadings(
    { device_id: deviceId, sensor_type: 'wind_speed,wind_dir', from: rangeQuery.from, to: rangeQuery.to, interval: rangeQuery.interval },
    pollChartMs,
  );

  if (deviceQuery.isLoading) {
    return <LoadingState className="py-10" label="Memuat detail stasiun…" />;
  }

  if (deviceQuery.isError || !deviceQuery.data) {
    return <ErrorState error={deviceQuery.error} onRetry={() => deviceQuery.refetch()} title="Gagal memuat detail stasiun" />;
  }

  const device = deviceQuery.data;
  const health = healthQuery.data;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-sm sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <Link href="/" className="text-xs font-medium text-muted-foreground hover:text-foreground">
              ← Kembali ke Overview
            </Link>
            <h1 className="mt-1 text-xl font-semibold text-foreground">{device.name}</h1>
            <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
              <span className="font-mono">{device.code}</span>
              {device.location ? (
                <span className="flex items-center gap-1">
                  <MapPin aria-hidden="true" className="size-3.5" />
                  {device.location.name} ({Number(device.location.latitude).toFixed(4)}, {Number(device.location.longitude).toFixed(4)}
                  {device.location.altitude_m ? `, ${device.location.altitude_m}m` : ''})
                </span>
              ) : null}
            </div>
          </div>
          <div className="flex flex-wrap gap-1.5">
            <StatusBadge status={device.status} />
            <ConnectivityBadge connectivity={device.connectivity} />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3 border-t pt-4 sm:grid-cols-4">
          <HeaderStat icon={Battery} label="Baterai" value={device.health.battery_v !== null ? `${device.health.battery_v} V` : '—'} />
          <HeaderStat icon={SignalHigh} label="RSSI" value={device.health.rssi !== null ? `${device.health.rssi} dBm` : '—'} />
          <HeaderStat icon={Cpu} label="Firmware" value={device.health.firmware_version ?? '—'} />
          <HeaderStat
            icon={Clock}
            label="Terakhir terlihat"
            value={device.health.last_seen_at ? relativeTime(device.health.last_seen_at, now) : 'Belum pernah'}
          />
        </div>
      </div>

      <SectionCard title="Nilai Terkini" description="Semua channel sensor pada device ini">
        {latestQuery.isLoading ? (
          <LoadingState />
        ) : latestQuery.isError ? (
          <ErrorState error={latestQuery.error} onRetry={() => latestQuery.refetch()} />
        ) : !latestQuery.data || latestQuery.data.length === 0 ? (
          <EmptyState message="Stasiun belum pernah mengirim data" icon={Radio} />
        ) : (
          <LatestPanel readings={latestQuery.data} channelsWithoutData={health?.channels_without_data ?? []} now={now} />
        )}
      </SectionCard>

      <SectionCard
        title="Suhu & Kelembapan"
        description="Sumbu kiri °C, sumbu kanan %"
        action={<RangePicker value={range} onChange={setRange} />}
      >
        {tempHumidReadings.isLoading ? (
          <ChartSkeleton />
        ) : tempHumidReadings.isError ? (
          <ErrorState error={tempHumidReadings.error} onRetry={() => tempHumidReadings.refetch()} />
        ) : !tempHumidReadings.data || tempHumidReadings.data.data.timestamps.length === 0 ? (
          <EmptyState message="Belum ada data di rentang ini" />
        ) : (
          <TempHumidityChart data={tempHumidReadings.data.data} intervalSeconds={tempHumidReadings.data.meta.interval_seconds} />
        )}
      </SectionCard>

      <SectionCard
        title="Curah Hujan"
        description="Nilai dalam mm, dihitung dari selisih counter (deteksi reset otomatis)"
        action={
          <SegmentedControl
            value={rainInterval}
            onChange={setRainInterval}
            options={[
              { value: '1h', label: 'Per Jam' },
              { value: '1d', label: 'Per Hari' },
            ]}
            label="Interval curah hujan"
          />
        }
      >
        {rainReadings.isLoading ? (
          <ChartSkeleton height={260} />
        ) : rainReadings.isError ? (
          <ErrorState error={rainReadings.error} onRetry={() => rainReadings.refetch()} />
        ) : !rainReadings.data || rainReadings.data.data.timestamps.length === 0 ? (
          <EmptyState message="Belum ada data hujan di rentang ini" />
        ) : (
          <RainChart data={rainReadings.data.data} interval={rainInterval} />
        )}
      </SectionCard>

      <SectionCard title="Angin" description="Kecepatan (garis) dan arah (titik) — fallback wind-rose belum tersedia di backend">
        {windReadings.isLoading ? (
          <ChartSkeleton height={260} />
        ) : windReadings.isError ? (
          <ErrorState error={windReadings.error} onRetry={() => windReadings.refetch()} />
        ) : !windReadings.data || windReadings.data.data.timestamps.length === 0 ? (
          <EmptyState message="Belum ada data angin di rentang ini" />
        ) : (
          <WindChart data={windReadings.data.data} intervalSeconds={windReadings.data.meta.interval_seconds} />
        )}
      </SectionCard>

      <SectionCard title="Kesehatan Device" description="Tren baterai 24 jam, offset jam, dan status boot">
        {healthQuery.isLoading ? (
          <LoadingState />
        ) : healthQuery.isError ? (
          <ErrorState error={healthQuery.error} onRetry={() => healthQuery.refetch()} />
        ) : health ? (
          <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <HeaderStat icon={Clock} label="Clock Offset" value={health.clock_offset_s !== null ? `${health.clock_offset_s}s` : '—'} />
              <HeaderStat icon={Cpu} label="Uptime" value={health.uptime_s !== null ? `${Math.round(health.uptime_s / 3600)} jam` : '—'} />
              <HeaderStat icon={Clock} label="Boot Terakhir" value={health.last_boot_at ? formatWib(health.last_boot_at) : '—'} />
              <HeaderStat
                icon={Radio}
                label="Channel Tanpa Data"
                value={health.channels_without_data.length > 0 ? health.channels_without_data.join(', ') : 'Semua normal'}
              />
            </div>
            {health.battery_trend_24h.timestamps.length > 0 ? (
              <BatteryTrendChart timestamps={health.battery_trend_24h.timestamps} values={health.battery_trend_24h.values} />
            ) : (
              <EmptyState message="Belum ada data tren baterai" />
            )}
          </div>
        ) : null}
      </SectionCard>
    </div>
  );
}

function HeaderStat({ icon: Icon, label, value }: { icon: typeof Battery; label: string; value: string }) {
  return (
    <div className="flex items-center gap-2.5 rounded-md bg-muted/60 px-3 py-2.5">
      <Icon aria-hidden="true" className="size-4 shrink-0 text-navy-600" />
      <div className="min-w-0">
        <p className="truncate text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">{label}</p>
        <p className="truncate font-mono text-sm font-medium text-foreground">{value}</p>
      </div>
    </div>
  );
}
