'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import * as React from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { ErrorState } from '@/components/states/ErrorState';
import { Button, Field, Input, Select } from '@/components/ui';
import { apiSend } from '@/lib/api/client';
import { applyFieldErrors } from '@/lib/api/errors';
import { useSensors, useSensorTypes } from '@/lib/api/hooks';

const schema = z.object({
  sensor_id: z.string().min(1, 'Pilih sensor'),
  channel_key: z.string().max(32).optional(),
  installed_at: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

export function InstallSensorForm({ deviceId, onInstalled }: { deviceId: number; onInstalled: () => void }) {
  const queryClient = useQueryClient();
  const [typeFilter, setTypeFilter] = React.useState('');
  const sensorTypesQuery = useSensorTypes();
  const availableSensors = useSensors({ sensor_type: typeFilter || undefined, installed: 'false', per_page: 100 });

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { sensor_id: '', channel_key: '', installed_at: '' },
  });

  const [hasFieldError, setHasFieldError] = React.useState(false);

  const mutation = useMutation({
    mutationFn: async (values: FormValues) =>
      apiSend('POST', `/devices/${deviceId}/sensors`, {
        sensor_id: Number(values.sensor_id),
        channel_key: values.channel_key || undefined,
        // input datetime-local diasumsikan WIB, dikonversi ke UTC sebelum dikirim
        installed_at: values.installed_at ? new Date(`${values.installed_at}:00+07:00`).toISOString() : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['device-sensors', deviceId] });
      queryClient.invalidateQueries({ queryKey: ['sensors'] });
      queryClient.invalidateQueries({ queryKey: ['device', deviceId] });
      form.reset({ sensor_id: '', channel_key: '', installed_at: '' });
      setHasFieldError(false);
      onInstalled();
    },
    onError: (err) => {
      setHasFieldError(applyFieldErrors(err, form.setError, ['sensor_id', 'channel_key', 'installed_at']));
    },
  });

  const { errors } = form.formState;

  return (
    <form onSubmit={form.handleSubmit((v) => mutation.mutate(v))} className="flex flex-col gap-4" noValidate>
      <Field label="Filter Tipe Sensor" htmlFor="type-filter" hint="Mempersempit daftar sensor yang belum terpasang.">
        <Select id="type-filter" value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
          <option value="">Semua tipe</option>
          {sensorTypesQuery.data?.map((t) => (
            <option key={t.id} value={t.code}>
              {t.name} ({t.code})
            </option>
          ))}
        </Select>
      </Field>

      <Field label="Sensor" htmlFor="sensor_id" error={errors.sensor_id?.message}>
        <Select id="sensor_id" aria-invalid={Boolean(errors.sensor_id)} {...form.register('sensor_id')}>
          <option value="">{availableSensors.isLoading ? 'Memuat sensor…' : 'Pilih sensor yang belum terpasang'}</option>
          {availableSensors.data?.items.map((s) => (
            <option key={s.id} value={s.id}>
              {s.serial_number} — {s.sensor_type}
            </option>
          ))}
        </Select>
        {availableSensors.data?.items.length === 0 ? (
          <p className="text-xs text-muted-foreground">Tidak ada sensor bertipe ini yang tersedia. Tambahkan di Inventaris Sensor.</p>
        ) : null}
      </Field>

      <Field
        label="Channel Key"
        htmlFor="channel_key"
        error={errors.channel_key?.message}
        hint="Kosongkan untuk pakai kode tipe sensor sebagai default."
      >
        <Input id="channel_key" placeholder="temp_air_in" {...form.register('channel_key')} />
      </Field>

      <Field label="Waktu Pasang (WIB)" htmlFor="installed_at" error={errors.installed_at?.message} hint="Kosongkan untuk pakai waktu sekarang.">
        <Input id="installed_at" type="datetime-local" {...form.register('installed_at')} />
      </Field>

      {mutation.isError && !hasFieldError ? <ErrorState error={mutation.error} title="Gagal memasang sensor" /> : null}

      <Button type="submit" disabled={mutation.isPending}>
        {mutation.isPending ? 'Memasang…' : 'Pasang Sensor'}
      </Button>
    </form>
  );
}
