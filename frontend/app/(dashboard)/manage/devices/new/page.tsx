'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { SectionCard } from '@/components/dashboard/components';
import { ApiKeyDialog } from '@/components/manage/ApiKeyDialog';
import { ErrorState } from '@/components/states/ErrorState';
import { Button, Field, Input, Select, buttonClass } from '@/components/ui';
import { apiSend } from '@/lib/api/client';
import { applyFieldErrors } from '@/lib/api/errors';
import { useLocations } from '@/lib/api/hooks';
import type { DeviceCreated } from '@/lib/api/types';

const schema = z.object({
  code: z
    .string()
    .min(1, 'Kode wajib diisi')
    .max(32, 'Maksimal 32 karakter')
    .regex(/^[A-Z0-9-]+$/, 'Hanya huruf kapital, angka, dan tanda hubung (mis. WS-SMG-001)'),
  name: z.string().min(1, 'Nama wajib diisi').max(150, 'Maksimal 150 karakter'),
  location_id: z.string().min(1, 'Lokasi wajib dipilih'),
  create_default_channels: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

export default function NewDevicePage() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const locationsQuery = useLocations();
  const [created, setCreated] = React.useState<DeviceCreated | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { code: '', name: '', location_id: '', create_default_channels: true },
  });

  const [hasFieldError, setHasFieldError] = React.useState(false);

  const mutation = useMutation({
    mutationFn: async (values: FormValues) =>
      (
        await apiSend<DeviceCreated>('POST', '/devices', {
          code: values.code,
          name: values.name,
          location_id: Number(values.location_id),
          create_default_channels: values.create_default_channels,
        })
      ).data,
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['devices'] });
      queryClient.invalidateQueries({ queryKey: ['overview'] });
      setHasFieldError(false);
      setCreated(data);
    },
    onError: (err) => {
      setHasFieldError(applyFieldErrors(err, form.setError, ['code', 'name', 'location_id']));
    },
  });

  const { errors } = form.formState;

  return (
    <div className="mx-auto flex w-full max-w-2xl flex-col gap-6">
      <div>
        <Link href="/manage/devices" className="text-xs font-medium text-muted-foreground hover:text-foreground">
          ← Kembali ke daftar device
        </Link>
        <h1 className="mt-1 text-xl font-semibold text-foreground">Registrasi Device Baru</h1>
      </div>

      <SectionCard title="Data Device" description="API key akan dibuat otomatis dan hanya ditampilkan sekali.">
        <form onSubmit={form.handleSubmit((v) => mutation.mutate(v))} className="flex flex-col gap-4" noValidate>
          <Field label="Kode Device" htmlFor="code" error={errors.code?.message} hint="Dipakai sebagai device_id di payload telemetry.">
            <Input
              id="code"
              placeholder="WS-SMG-001"
              aria-invalid={Boolean(errors.code)}
              {...form.register('code', { setValueAs: (v: string) => v.toUpperCase() })}
            />
          </Field>

          <Field label="Nama Stasiun" htmlFor="name" error={errors.name?.message}>
            <Input id="name" placeholder="Stasiun Semarang Pelabuhan" aria-invalid={Boolean(errors.name)} {...form.register('name')} />
          </Field>

          <Field label="Lokasi" htmlFor="location_id" error={errors.location_id?.message}>
            <Select id="location_id" aria-invalid={Boolean(errors.location_id)} {...form.register('location_id')}>
              <option value="">{locationsQuery.isLoading ? 'Memuat lokasi…' : 'Pilih lokasi'}</option>
              {locationsQuery.data?.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.name} ({l.code})
                </option>
              ))}
            </Select>
          </Field>

          <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" className="mt-0.5 size-4 accent-primary" {...form.register('create_default_channels')} />
            <span>
              Buat 7 channel default
              <span className="block text-xs text-muted-foreground">channel_key = kode tipe sensor (temp_air, humidity, …)</span>
            </span>
          </label>

          {mutation.isError && !hasFieldError ? (
            <ErrorState error={mutation.error} title="Gagal menyimpan device" />
          ) : null}

          <div className="flex justify-end gap-2 border-t pt-4">
            <Link href="/manage/devices" className={buttonClass('outline')}>
              Batal
            </Link>
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending ? 'Menyimpan…' : 'Simpan Device'}
            </Button>
          </div>
        </form>
      </SectionCard>

      <ApiKeyDialog
        open={created !== null}
        apiKey={created?.credential.api_key ?? null}
        title={`Device ${created?.code ?? ''} terdaftar`}
        onClose={() => {
          const id = created?.id;
          setCreated(null);
          router.push(id ? `/manage/devices/${id}/edit` : '/manage/devices');
        }}
      />
    </div>
  );
}
