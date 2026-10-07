'use client';

import { Activity, Plus, Radio, Wifi, WifiOff } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';
import { DashboardPageHeader, MetricCard } from '@/components/dashboard/components';
import { usePollInterval } from '@/components/refresh-context';
import { CardGridSkeleton } from '@/components/states/LoadingState';
import { EmptyState } from '@/components/states/EmptyState';
import { ErrorState, StaleDataBanner } from '@/components/states/ErrorState';
import { StationCard } from '@/components/stations/StationCard';
import { buttonClass } from '@/components/ui';
import { useOverview } from '@/lib/api/hooks';
import { formatWib } from '@/lib/time';

export default function OverviewPage() {
  const pollMs = usePollInterval(30_000);
  const { data, isLoading, error, refetch, isError } = useOverview(pollMs);
  const [now, setNow] = React.useState(() => new Date());

  React.useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 10_000);
    return () => clearInterval(timer);
  }, []);

  const totals = data?.totals;
  const stations = data?.stations ?? [];

  return (
    <div className="flex flex-col gap-6">
      <DashboardPageHeader
        eyebrow="PT LUWES INOVASI MANDIRI — IOT PLATFORM"
        title="Monitoring Stasiun Cuaca"
        description="Ringkasan seluruh stasiun pemantau cuaca di lapangan, status konektivitas, dan bacaan sensor terkini."
        meta={
          <div className="flex items-center gap-2">
            <Link href="/manage/devices/new" className={buttonClass('secondary', 'sm', 'bg-white/10 text-white hover:bg-white/20')}>
              <Plus aria-hidden="true" className="size-3.5" />
              Tambah Device
            </Link>
            {data?.generated_at ? (
              <span className="hidden rounded-full bg-white/15 px-3 py-1 font-mono text-xs text-white sm:inline">
                {formatWib(data.generated_at)}
              </span>
            ) : null}
          </div>
        }
      />

      {isError && data ? <StaleDataBanner onRetry={() => refetch()} /> : null}

      <section aria-labelledby="metrics-heading" className="space-y-3">
        <h2 id="metrics-heading" className="px-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
          Ringkasan Stasiun
        </h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <MetricCard
            label="Total Device"
            value={totals?.devices ?? (isLoading ? '…' : 0)}
            helper={`${totals?.active ?? 0} aktif · ${totals?.maintenance ?? 0} maintenance · ${totals?.provisioned ?? 0} provisioned`}
            icon={Radio}
            tone="neutral"
          />
          <MetricCard
            label="Online"
            value={totals?.online ?? (isLoading ? '…' : 0)}
            helper="Mengirim data ≤ 5 menit lalu"
            icon={Wifi}
            tone="success"
          />
          <MetricCard
            label="Stale"
            value={totals?.stale ?? (isLoading ? '…' : 0)}
            helper="Diam 5–15 menit"
            icon={Activity}
            tone="warning"
          />
          <MetricCard
            label="Offline"
            value={totals?.offline ?? (isLoading ? '…' : 0)}
            helper={`${totals?.never ?? 0} belum pernah kirim data`}
            icon={WifiOff}
            tone="error"
          />
        </div>
      </section>

      <section aria-labelledby="stations-heading" className="space-y-3">
        <div className="flex items-center justify-between px-1">
          <h2 id="stations-heading" className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            Daftar Stasiun ({stations.length})
          </h2>
          <span className="text-xs text-muted-foreground">Polling setiap 30 detik</span>
        </div>

        {isLoading ? (
          <CardGridSkeleton count={4} />
        ) : error && !data ? (
          <ErrorState error={error} onRetry={() => refetch()} title="Gagal memuat daftar stasiun" />
        ) : stations.length === 0 ? (
          <EmptyState
            message="Belum ada stasiun terdaftar"
            description="Daftarkan device stasiun cuaca pertama Anda untuk mulai memantau."
            action={
              <Link href="/manage/devices/new" className={buttonClass('primary', 'sm')}>
                <Plus aria-hidden="true" className="size-3.5" />
                Tambah Device
              </Link>
            }
          />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            {stations.map((st) => (
              <StationCard key={st.id} station={st} now={now} />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
