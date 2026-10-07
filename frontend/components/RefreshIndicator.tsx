'use client';

import { Pause, Play, RotateCw } from 'lucide-react';
import * as React from 'react';
import { relativeTime } from '@/lib/time';

export function RefreshIndicator({
  lastUpdatedAt,
  isFetching,
  autoRefresh,
  onToggleAutoRefresh,
  onRefresh,
}: {
  lastUpdatedAt: Date | null;
  isFetching: boolean;
  autoRefresh: boolean;
  onToggleAutoRefresh: () => void;
  onRefresh: () => void;
}) {
  const [, setTick] = React.useState(0);

  // Update tulisan "X detik lalu" tiap 5 detik
  React.useEffect(() => {
    const timer = setInterval(() => setTick((t) => t + 1), 5000);
    return () => clearInterval(timer);
  }, []);

  const rel = lastUpdatedAt ? relativeTime(lastUpdatedAt) : 'belum diperbarui';

  return (
    <div className="flex items-center gap-2 text-xs text-muted-foreground">
      <span className="hidden sm:inline">
        Terakhir diperbarui: <strong className="font-semibold text-foreground">{rel}</strong>
      </span>
      <button
        type="button"
        onClick={onRefresh}
        disabled={isFetching}
        title="Perbarui sekarang"
        className="inline-flex size-7 items-center justify-center rounded-md border bg-card text-foreground transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50"
      >
        <RotateCw aria-hidden="true" className={`size-3.5 ${isFetching ? 'animate-spin text-primary' : ''}`} />
        <span className="sr-only">Perbarui sekarang</span>
      </button>
      <button
        type="button"
        onClick={onToggleAutoRefresh}
        title={autoRefresh ? 'Jeda auto-refresh' : 'Nyalakan auto-refresh'}
        className={`inline-flex items-center gap-1 rounded-md px-2 py-1 font-medium transition-colors ${
          autoRefresh ? 'bg-success-tint text-success' : 'bg-muted text-muted-foreground'
        }`}
      >
        {autoRefresh ? <Pause aria-hidden="true" className="size-3" /> : <Play aria-hidden="true" className="size-3" />}
        <span>{autoRefresh ? 'Auto 30s' : 'Dijeda'}</span>
      </button>
    </div>
  );
}
