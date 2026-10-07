'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import * as React from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import { ErrorState } from '@/components/states/ErrorState';
import { Button, Field, Input } from '@/components/ui';
import { apiSend } from '@/lib/api/client';
import { applyFieldErrors } from '@/lib/api/errors';
import { useSensorCalibrations } from '@/lib/api/hooks';
import type { Calibration } from '@/lib/api/types';
import { formatWib } from '@/lib/time';

const schema = z.object({
  scale_factor: z.coerce.number().refine((n) => n !== 0, 'scale_factor tidak boleh 0'),
  offset_value: z.coerce.number(),
  effective_from: z.string().optional(),
  note: z.string().max(255).optional(),
});

type FormInput = z.input<typeof schema>;
type FormValues = z.output<typeof schema>;

export function CalibrationForm({
  sensorId,
  sensorSerial,
  onSaved,
}: {
  sensorId: number;
  sensorSerial: string;
  onSaved?: () => void;
}) {
  const queryClient = useQueryClient();
  const calibrationsQuery = useSensorCalibrations(sensorId);

  const form = useForm<FormInput, unknown, FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { scale_factor: 1.0, offset_value: 0.0, effective_from: '', note: '' },
  });

  const scale = Number(useWatch({ control: form.control, name: 'scale_factor' }));
  const offset = Number(useWatch({ control: form.control, name: 'offset_value' }));

  const previewRaw = 27.4;
  const previewCalibrated = Number.isFinite(scale) && Number.isFinite(offset) ? previewRaw * scale + offset : null;

  const [hasFieldError, setHasFieldError] = React.useState(false);

  const mutation = useMutation({
    mutationFn: async (values: FormValues) =>
      apiSend<Calibration>('POST', `/sensors/${sensorId}/calibrations`, {
        scale_factor: values.scale_factor,
        offset_value: values.offset_value,
        effective_from: values.effective_from ? new Date(`${values.effective_from}:00+07:00`).toISOString() : undefined,
        note: values.note || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sensor-calibrations', sensorId] });
      form.reset({ scale_factor: 1.0, offset_value: 0.0, effective_from: '', note: '' });
      setHasFieldError(false);
      onSaved?.();
    },
    onError: (err) => {
      setHasFieldError(applyFieldErrors(err, form.setError, ['scale_factor', 'offset_value', 'effective_from', 'note']));
    },
  });

  const { errors } = form.formState;

  return (
    <div className="flex flex-col gap-5">
      <form onSubmit={form.handleSubmit((v) => mutation.mutate(v))} className="flex flex-col gap-4" noValidate>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Scale Factor" htmlFor="scale_factor" error={errors.scale_factor?.message}>
            <Input id="scale_factor" type="number" step="any" {...form.register('scale_factor')} />
          </Field>
          <Field label="Offset Value" htmlFor="offset_value" error={errors.offset_value?.message}>
            <Input id="offset_value" type="number" step="any" {...form.register('offset_value')} />
          </Field>
        </div>

        <div className="rounded-md bg-muted/60 px-3 py-2 text-xs">
          <span className="font-semibold text-foreground">Preview Langsung: </span>
          <span className="text-muted-foreground">
            Mentah {previewRaw.toLocaleString('id-ID')} →{' '}
            <strong className="font-mono text-foreground">
              {previewCalibrated !== null ? previewCalibrated.toFixed(2).replace('.', ',') : '—'}
            </strong>
          </span>
        </div>

        <Field
          label="Berlaku Mulai (WIB)"
          htmlFor="effective_from"
          error={errors.effective_from?.message}
          hint="Kosongkan untuk berlaku mulai sekarang (harus >= sekarang - 5m)."
        >
          <Input id="effective_from" type="datetime-local" {...form.register('effective_from')} />
        </Field>

        <Field label="Catatan Kalibrasi" htmlFor="note" error={errors.note?.message}>
          <Input id="note" placeholder="Contoh: Kalibrasi ulang termometer referensi lab" {...form.register('note')} />
        </Field>

        {mutation.isError && !hasFieldError ? <ErrorState error={mutation.error} title="Gagal menyimpan kalibrasi" /> : null}

        <Button type="submit" disabled={mutation.isPending}>
          {mutation.isPending ? 'Menyimpan…' : `Simpan Kalibrasi (${sensorSerial})`}
        </Button>
      </form>

      <div className="border-t pt-3">
        <h4 className="text-xs font-semibold text-muted-foreground uppercase">Riwayat Kalibrasi</h4>
        {calibrationsQuery.isLoading ? (
          <p className="mt-2 text-xs text-muted-foreground">Memuat riwayat…</p>
        ) : !calibrationsQuery.data || calibrationsQuery.data.length === 0 ? (
          <p className="mt-2 text-xs text-muted-foreground">Belum ada riwayat kalibrasi untuk sensor ini.</p>
        ) : (
          <ul role="list" className="mt-2 divide-y text-xs">
            {calibrationsQuery.data.map((c) => (
              <li key={c.id} className="py-2">
                <div className="flex items-center justify-between">
                  <span className="font-mono font-medium">
                    ×{c.scale_factor} {c.offset_value >= 0 ? `+${c.offset_value}` : c.offset_value}
                  </span>
                  <span className="text-muted-foreground">{formatWib(c.effective_from)}</span>
                </div>
                {c.note ? <p className="mt-0.5 text-muted-foreground">{c.note}</p> : null}
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
