'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Gauge, Plus } from 'lucide-react';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { DashboardPageHeader, SectionCard } from '@/components/dashboard/components';
import { EmptyState } from '@/components/states/EmptyState';
import { ErrorState } from '@/components/states/ErrorState';
import { TableSkeleton } from '@/components/states/LoadingState';
import { Button, Dialog, Field, Input, Select, buttonClass } from '@/components/ui';
import { apiSend } from '@/lib/api/client';
import { applyFieldErrors } from '@/lib/api/errors';
import { useSensors, useSensorTypes } from '@/lib/api/hooks';
import { formatWib } from '@/lib/time';

const schema = z.object({
  serial_number: z.string().min(1, 'Nomor seri wajib diisi').max(64),
  sensor_type: z.string().min(1, 'Pilih tipe sensor'),
  manufacturer: z.string().max(100).optional(),
  model: z.string().max(100).optional(),
});

type FormValues = z.infer<typeof schema>;

export default function SensorsInventoryPage() {
  const [typeFilter, setTypeFilter] = React.useState('');
  const [installedFilter, setInstalledFilter] = React.useState('');
  const [dialogOpen, setDialogOpen] = React.useState(false);

  const sensorTypesQuery = useSensorTypes();
  const sensorsQuery = useSensors({
    sensor_type: typeFilter || undefined,
    installed: (installedFilter || undefined) as 'true' | 'false' | undefined,
    per_page: 50,
  });

  return (
    <div className="flex flex-col gap-6">
      <DashboardPageHeader
        eyebrow="MANAJEMEN PERANGKAT"
        title="Inventaris Sensor"
        description="Daftar sensor fisik, status pemasangan, dan riwayat kalibrasi tersedia di halaman detail device."
        meta={
          <button type="button" onClick={() => setDialogOpen(true)} className={buttonClass('secondary', 'sm', 'bg-white/10 text-white hover:bg-white/20')}>
            <Plus aria-hidden="true" className="size-3.5" />
            Tambah Sensor
          </button>
        }
      />

      <div className="flex flex-wrap gap-2">
        <Select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} aria-label="Filter tipe sensor" className="w-auto">
          <option value="">Semua Tipe</option>
          {sensorTypesQuery.data?.map((t) => (
            <option key={t.id} value={t.code}>
              {t.name}
            </option>
          ))}
        </Select>
        <Select value={installedFilter} onChange={(e) => setInstalledFilter(e.target.value)} aria-label="Filter status pasang" className="w-auto">
          <option value="">Semua Status</option>
          <option value="true">Terpasang</option>
          <option value="false">Belum Terpasang</option>
        </Select>
      </div>

      <SectionCard title="Daftar Sensor" contentClassName="p-0">
        {sensorsQuery.isLoading ? (
          <TableSkeleton rows={6} cols={5} />
        ) : sensorsQuery.isError ? (
          <div className="p-5">
            <ErrorState error={sensorsQuery.error} onRetry={() => sensorsQuery.refetch()} />
          </div>
        ) : !sensorsQuery.data || sensorsQuery.data.items.length === 0 ? (
          <div className="p-5">
            <EmptyState icon={Gauge} message="Tidak ada sensor yang cocok dengan filter" />
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px] text-left text-sm">
              <thead className="bg-muted text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                <tr>
                  <th scope="col" className="px-4 py-2.5">Nomor Seri</th>
                  <th scope="col" className="px-3 py-2.5">Tipe</th>
                  <th scope="col" className="px-3 py-2.5">Manufaktur</th>
                  <th scope="col" className="px-3 py-2.5">Model</th>
                  <th scope="col" className="px-3 py-2.5">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {sensorsQuery.data.items.map((s) => (
                  <tr key={s.id} className="hover:bg-muted/50">
                    <td className="px-4 py-3 font-mono text-xs font-medium">{s.serial_number}</td>
                    <td className="px-3 py-3 text-xs">{s.sensor_type}</td>
                    <td className="px-3 py-3 text-xs text-muted-foreground">{s.manufacturer ?? '—'}</td>
                    <td className="px-3 py-3 text-xs text-muted-foreground">{s.model ?? '—'}</td>
                    <td className="px-3 py-3 text-xs">
                      {s.retired_at ? (
                        <span className="text-muted-foreground">Retired sejak {formatWib(s.retired_at)}</span>
                      ) : (
                        <span className="font-medium text-success">Aktif</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </SectionCard>

      <NewSensorDialog open={dialogOpen} onClose={() => setDialogOpen(false)} />
    </div>
  );
}

function NewSensorDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const queryClient = useQueryClient();
  const sensorTypesQuery = useSensorTypes();
  const [hasFieldError, setHasFieldError] = React.useState(false);

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { serial_number: '', sensor_type: '', manufacturer: '', model: '' },
  });

  const mutation = useMutation({
    mutationFn: async (values: FormValues) => apiSend('POST', '/sensors', values),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sensors'] });
      form.reset();
      setHasFieldError(false);
      onClose();
    },
    onError: (err) => {
      setHasFieldError(applyFieldErrors(err, form.setError, ['serial_number', 'sensor_type', 'manufacturer', 'model']));
    },
  });

  const { errors } = form.formState;

  return (
    <Dialog open={open} onClose={onClose} title="Tambah Sensor Baru">
      <form onSubmit={form.handleSubmit((v) => mutation.mutate(v))} className="flex flex-col gap-4" noValidate>
        <Field label="Nomor Seri" htmlFor="serial_number" error={errors.serial_number?.message}>
          <Input id="serial_number" placeholder="TA-0101" {...form.register('serial_number')} />
        </Field>
        <Field label="Tipe Sensor" htmlFor="sensor_type" error={errors.sensor_type?.message}>
          <Select id="sensor_type" {...form.register('sensor_type')}>
            <option value="">Pilih tipe</option>
            {sensorTypesQuery.data?.map((t) => (
              <option key={t.id} value={t.code}>
                {t.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Manufaktur" htmlFor="manufacturer" error={errors.manufacturer?.message}>
          <Input id="manufacturer" placeholder="Sensirion" {...form.register('manufacturer')} />
        </Field>
        <Field label="Model" htmlFor="model" error={errors.model?.message}>
          <Input id="model" placeholder="SHT45" {...form.register('model')} />
        </Field>

        {mutation.isError && !hasFieldError ? <ErrorState error={mutation.error} title="Gagal menambah sensor" /> : null}

        <div className="flex justify-end gap-2 border-t pt-4">
          <Button type="button" variant="outline" onClick={onClose}>
            Batal
          </Button>
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? 'Menyimpan…' : 'Simpan'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
