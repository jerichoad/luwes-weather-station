'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { SectionCard } from '@/components/dashboard/components';
import { RotateKeyDialog } from '@/components/manage/RotateKeyDialog';
import { StatusBadge } from '@/components/stations/Badges';
import { ErrorState } from '@/components/states/ErrorState';
import { LoadingState } from '@/components/states/LoadingState';
import { Button, Field, Input, Select, Textarea, buttonClass } from '@/components/ui';
import { apiSend } from '@/lib/api/client';
import { applyFieldErrors } from '@/lib/api/errors';
import { useDevice, useLocations } from '@/lib/api/hooks';
import type { DeviceDetail } from '@/lib/api/types';

const schema = z
  .object({
    name: z.string().min(1, 'Nama wajib diisi').max(150),
    location_id: z.string().min(1, 'Lokasi wajib dipilih'),
    status: z.string(),
    status_reason: z.string(),
  })
  .refine(
    (data) => {
      if (data.status && data.status.length > 0 && (!data.status_reason || data.status_reason.trim().length === 0)) {
        return false;
      }
      return true;
    },
    { message: 'Alasan perubahan status wajib diisi jika status diubah.', path: ['status_reason'] },
  );

type FormValues = z.infer<typeof schema>;

export default function EditDevicePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = React.use(params);
  const deviceId = Number(id);

  const queryClient = useQueryClient();
  const deviceQuery = useDevice(deviceId);
  const locationsQuery = useLocations();

  const [hasFieldError, setHasFieldError] = React.useState(false);
  const [successMsg, setSuccessMsg] = React.useState<string | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', location_id: '', status: '', status_reason: '' },
  });

  const device = deviceQuery.data;

  React.useEffect(() => {
    if (!device) return;
    form.reset({
      name: device.name,
      location_id: device.location?.id ? String(device.location.id) : '',
      status: '',
      status_reason: '',
    });
  }, [device, form]);

  const mutation = useMutation({
    mutationFn: async (values: FormValues) => {
      const payload: Record<string, unknown> = {
        name: values.name,
        location_id: Number(values.location_id),
      };
      if (values.status && values.status !== device?.status) {
        payload.status = values.status;
        payload.status_reason = values.status_reason;
      }
      return (await apiSend<DeviceDetail>('PATCH', `/devices/${deviceId}`, payload)).data;
    },
    onSuccess: (updated) => {
      queryClient.setQueryData(['device', deviceId], updated);
      queryClient.invalidateQueries({ queryKey: ['devices'] });
      queryClient.invalidateQueries({ queryKey: ['overview'] });
      setHasFieldError(false);
      setSuccessMsg('Perubahan berhasil disimpan.');
      form.setValue('status', '');
      form.setValue('status_reason', '');
      setTimeout(() => setSuccessMsg(null), 3000);
    },
    onError: (err) => {
      setHasFieldError(applyFieldErrors(err, form.setError, ['name', 'location_id', 'status', 'status_reason']));
    },
  });

  if (deviceQuery.isLoading) return <LoadingState className="py-10" label="Memuat device…" />;
  if (deviceQuery.isError || !device) return <ErrorState error={deviceQuery.error} title="Gagal memuat device" />;

  const { errors } = form.formState;
  const currentStatus = device.status;
  const allowed = device.allowed_transitions;

  return (
    <div className="mx-auto flex w-full max-w-2xl flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link href="/manage/devices" className="text-xs font-medium text-muted-foreground hover:text-foreground">
            ← Kembali ke daftar device
          </Link>
          <h1 className="mt-1 text-xl font-semibold text-foreground">Edit Device — {device.code}</h1>
        </div>
        <div className="flex gap-2">
          <Link href={`/manage/devices/${deviceId}/sensors`} className={buttonClass('secondary', 'sm')}>
            Kelola Sensor
          </Link>
          <RotateKeyDialog deviceId={device.id} deviceCode={device.code} />
        </div>
      </div>

      <SectionCard title="Data Utama">
        <form onSubmit={form.handleSubmit((v) => mutation.mutate(v))} className="flex flex-col gap-4" noValidate>
          <Field label="Kode Device" htmlFor="code" hint="Kode unik (tidak dapat diubah setelah dibuat).">
            <Input id="code" value={device.code} disabled />
          </Field>

          <Field label="Nama Stasiun" htmlFor="name" error={errors.name?.message}>
            <Input id="name" aria-invalid={Boolean(errors.name)} {...form.register('name')} />
          </Field>

          <Field label="Lokasi" htmlFor="location_id" error={errors.location_id?.message}>
            <Select id="location_id" aria-invalid={Boolean(errors.location_id)} {...form.register('location_id')}>
              <option value="">Pilih lokasi</option>
              {locationsQuery.data?.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.name} ({l.code})
                </option>
              ))}
            </Select>
          </Field>

          <div className="rounded-md border p-4">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-xs font-semibold text-foreground">Status Operasional Saat Ini</p>
                <div className="mt-1">
                  <StatusBadge status={currentStatus} />
                </div>
              </div>
            </div>

            {allowed.length > 0 ? (
              <div className="mt-4 flex flex-col gap-3 border-t pt-3">
                <Field
                  label="Ubah Status Ke"
                  htmlFor="status"
                  error={errors.status?.message}
                  hint="Hanya menampilkan status yang diizinkan oleh state machine."
                >
                  <Select id="status" {...form.register('status')}>
                    <option value="">— Tetap ({currentStatus}) —</option>
                    {allowed.map((s) => (
                      <option key={s} value={s}>
                        {s}
                      </option>
                    ))}
                  </Select>
                </Field>

                <Field
                  label="Alasan Perubahan Status"
                  htmlFor="status_reason"
                  error={errors.status_reason?.message}
                  hint="Wajib dicatat di riwayat transisi status."
                >
                  <Textarea
                    id="status_reason"
                    placeholder="Contoh: Pemeliharaan berkala sensor tekanan"
                    aria-invalid={Boolean(errors.status_reason)}
                    {...form.register('status_reason')}
                  />
                </Field>
              </div>
            ) : (
              <p className="mt-2 text-xs text-muted-foreground">Status decommissioned adalah status akhir (terminal), tidak dapat diubah lagi.</p>
            )}
          </div>

          {successMsg ? <p className="rounded-md bg-success-tint p-3 text-xs font-semibold text-success">{successMsg}</p> : null}

          {mutation.isError && !hasFieldError ? <ErrorState error={mutation.error} title="Gagal memperbarui device" /> : null}

          <div className="flex justify-end gap-2 border-t pt-4">
            <Link href="/manage/devices" className={buttonClass('outline')}>
              Batal
            </Link>
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending ? 'Menyimpan…' : 'Simpan Perubahan'}
            </Button>
          </div>
        </form>
      </SectionCard>
    </div>
  );
}
