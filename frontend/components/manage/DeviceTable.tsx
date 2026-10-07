'use client';

import { Edit2, Plus, Radio, Search } from 'lucide-react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import * as React from 'react';
import { ConnectivityBadge, StatusBadge } from '@/components/stations/Badges';
import { EmptyState } from '@/components/states/EmptyState';
import { ErrorState } from '@/components/states/ErrorState';
import { TableSkeleton } from '@/components/states/LoadingState';
import { Button, Input, Select, buttonClass } from '@/components/ui';
import { useDevices, useLocations } from '@/lib/api/hooks';
import { formatRelativeWithWib } from '@/lib/time';
import { cn } from '@/lib/utils';

function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = React.useState(value);
  React.useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(timer);
  }, [value, delayMs]);
  return debounced;
}

export function DeviceTable() {
  const router = useRouter();
  const searchParams = useSearchParams();

  const qParam = searchParams.get('q') ?? '';
  const statusParam = searchParams.get('status') ?? '';
  const locationParam = searchParams.get('location_id') ?? '';
  const pageParam = Number(searchParams.get('page') ?? '1');

  const [qInput, setQInput] = React.useState(qParam);
  const debouncedQ = useDebouncedValue(qInput, 300);

  React.useEffect(() => {
    if (debouncedQ === qParam) return;
    const params = new URLSearchParams(searchParams.toString());
    if (debouncedQ) params.set('q', debouncedQ);
    else params.delete('q');
    params.set('page', '1');
    router.replace(`?${params.toString()}`);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedQ]);

  function setParam(key: string, value: string) {
    const params = new URLSearchParams(searchParams.toString());
    if (value) params.set(key, value);
    else params.delete(key);
    params.set('page', '1');
    router.replace(`?${params.toString()}`);
  }

  function resetFilters() {
    setQInput('');
    router.replace('?page=1');
  }

  const locationsQuery = useLocations();
  const devicesQuery = useDevices({
    q: qParam || undefined,
    status: statusParam || undefined,
    location_id: locationParam ? Number(locationParam) : undefined,
    page: pageParam,
    per_page: 20,
  });

  const hasFilters = Boolean(qParam || statusParam || locationParam);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-1 flex-wrap items-center gap-2">
          <div className="relative min-w-[200px] flex-1 sm:max-w-xs">
            <Search aria-hidden="true" className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={qInput}
              onChange={(e) => setQInput(e.target.value)}
              placeholder="Cari kode atau nama…"
              className="pl-8"
              aria-label="Cari device"
            />
          </div>
          <Select value={statusParam} onChange={(e) => setParam('status', e.target.value)} aria-label="Filter status" className="w-auto">
            <option value="">Semua Status</option>
            <option value="provisioned">Provisioned</option>
            <option value="active">Active</option>
            <option value="maintenance">Maintenance</option>
            <option value="decommissioned">Decommissioned</option>
          </Select>
          <Select
            value={locationParam}
            onChange={(e) => setParam('location_id', e.target.value)}
            aria-label="Filter lokasi"
            className="w-auto"
          >
            <option value="">Semua Lokasi</option>
            {locationsQuery.data?.map((l) => (
              <option key={l.id} value={l.id}>
                {l.name}
              </option>
            ))}
          </Select>
        </div>
        <Link href="/manage/devices/new" className={buttonClass('primary', 'sm')}>
          <Plus aria-hidden="true" className="size-3.5" />
          Tambah Device
        </Link>
      </div>

      <div className="overflow-hidden rounded-lg border bg-card shadow-sm">
        {devicesQuery.isLoading ? (
          <TableSkeleton rows={6} cols={6} />
        ) : devicesQuery.isError ? (
          <div className="p-5">
            <ErrorState error={devicesQuery.error} onRetry={() => devicesQuery.refetch()} />
          </div>
        ) : !devicesQuery.data || devicesQuery.data.items.length === 0 ? (
          <div className="p-5">
            <EmptyState
              icon={Radio}
              message="Tidak ada device yang cocok dengan filter"
              action={
                hasFilters ? (
                  <Button variant="secondary" size="sm" onClick={resetFilters}>
                    Reset Filter
                  </Button>
                ) : undefined
              }
            />
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] table-fixed border-separate border-spacing-0 text-left text-sm">
              <thead>
                <tr className="text-muted-foreground">
                  <Th>Kode</Th>
                  <Th>Nama</Th>
                  <Th>Lokasi</Th>
                  <Th>Status</Th>
                  <Th>Konektivitas</Th>
                  <Th>Terakhir Terlihat</Th>
                  <Th className="w-16 text-right">Aksi</Th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border/60">
                {devicesQuery.data.items.map((d) => (
                  <tr key={d.id} className="transition-colors hover:bg-muted/50">
                    <td className="px-4 py-3 font-mono text-xs text-foreground">{d.code}</td>
                    <td className="truncate px-3 py-3 font-medium text-foreground">
                      <Link href={`/stations/${d.id}`} className="hover:underline">
                        {d.name}
                      </Link>
                    </td>
                    <td className="truncate px-3 py-3 text-muted-foreground">{d.location?.name ?? '—'}</td>
                    <td className="px-3 py-3">
                      <StatusBadge status={d.status} />
                    </td>
                    <td className="px-3 py-3">
                      <ConnectivityBadge connectivity={d.connectivity} />
                    </td>
                    <td className="px-3 py-3 text-xs text-muted-foreground">{formatRelativeWithWib(d.last_seen_at)}</td>
                    <td className="px-3 py-3 text-right">
                      <Link
                        href={`/manage/devices/${d.id}/edit`}
                        title="Edit device"
                        className="inline-flex size-7 items-center justify-center rounded-md hover:bg-muted"
                      >
                        <Edit2 aria-hidden="true" className="size-3.5" />
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {devicesQuery.data && devicesQuery.data.meta.last_page > 1 ? (
          <div className="flex items-center justify-between border-t px-4 py-3 text-xs text-muted-foreground">
            <span>
              Halaman {devicesQuery.data.meta.page} dari {devicesQuery.data.meta.last_page} · {devicesQuery.data.meta.total} device
            </span>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={pageParam <= 1}
                onClick={() => setParam('page', String(pageParam - 1))}
              >
                Sebelumnya
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={pageParam >= devicesQuery.data.meta.last_page}
                onClick={() => setParam('page', String(pageParam + 1))}
              >
                Berikutnya
              </Button>
            </div>
          </div>
        ) : null}
      </div>
    </div>
  );
}

function Th({ children, className }: { children: React.ReactNode; className?: string }) {
  return (
    <th scope="col" className={cn('sticky top-0 border-b bg-muted px-4 py-2.5 text-xs font-semibold tracking-wide uppercase', className)}>
      {children}
    </th>
  );
}
