import { Suspense } from 'react';
import { DashboardPageHeader } from '@/components/dashboard/components';
import { DeviceTable } from '@/components/manage/DeviceTable';
import { TableSkeleton } from '@/components/states/LoadingState';

export default function DevicesPage() {
  return (
    <div className="flex flex-col gap-6">
      <DashboardPageHeader
        eyebrow="MANAJEMEN PERANGKAT"
        title="Daftar Device Stasiun"
        description="Kelola seluruh unit stasiun pemantau, lokasi penempatan, status operasional, dan riwayat kredensial."
      />
      <Suspense fallback={<TableSkeleton />}>
        <DeviceTable />
      </Suspense>
    </div>
  );
}
