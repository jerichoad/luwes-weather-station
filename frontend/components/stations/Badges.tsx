import type { Connectivity, DeviceStatus } from '@/lib/api/types';
import { cn } from '@/lib/utils';

export function ConnectivityBadge({ connectivity }: { connectivity: Connectivity }) {
  const meta: Record<Connectivity, { dot: string; label: string; text: string; bg: string }> = {
    online: { dot: 'bg-success', label: 'Online', text: 'text-success', bg: 'bg-success-tint' },
    stale: { dot: 'bg-warning', label: 'Stale (>5m)', text: 'text-warning', bg: 'bg-warning-tint' },
    offline: { dot: 'bg-error', label: 'Offline (>15m)', text: 'text-error', bg: 'bg-error-tint' },
    never: { dot: 'bg-muted-foreground', label: 'Belum ada data', text: 'text-muted-foreground', bg: 'bg-muted' },
  };

  const st = meta[connectivity] ?? meta.never;

  return (
    <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap', st.bg, st.text)}>
      <span aria-hidden="true" className={cn('size-1.5 rounded-full', st.dot)} />
      {st.label}
    </span>
  );
}

export function StatusBadge({ status }: { status: DeviceStatus }) {
  const meta: Record<DeviceStatus, { label: string; cls: string }> = {
    provisioned: { label: 'Provisioned', cls: 'bg-navy-50 text-navy-600' },
    active: { label: 'Active', cls: 'bg-success-tint text-success' },
    maintenance: { label: 'Maintenance', cls: 'bg-warning-tint text-warning' },
    decommissioned: { label: 'Decommissioned', cls: 'bg-muted text-muted-foreground' },
  };

  const st = meta[status] ?? { label: status, cls: 'bg-muted text-foreground' };

  return (
    <span className={cn('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap', st.cls)}>
      {st.label}
    </span>
  );
}
