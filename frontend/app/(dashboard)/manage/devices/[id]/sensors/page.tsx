'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import { History, Plug, SlidersHorizontal, Unplug } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';
import { SectionCard } from '@/components/dashboard/components';
import { CalibrationForm } from '@/components/manage/CalibrationForm';
import { InstallSensorForm } from '@/components/manage/InstallSensorForm';
import { EmptyState } from '@/components/states/EmptyState';
import { ErrorState } from '@/components/states/ErrorState';
import { LoadingState, TableSkeleton } from '@/components/states/LoadingState';
import { Button, Dialog, Field, Input } from '@/components/ui';
import { apiSend } from '@/lib/api/client';
import { useDevice, useDeviceSensors } from '@/lib/api/hooks';
import type { Installation } from '@/lib/api/types';
import { formatWib } from '@/lib/time';

export default function DeviceSensorsPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = React.use(params);
  const deviceId = Number(id);

  const deviceQuery = useDevice(deviceId);
  const activeQuery = useDeviceSensors(deviceId, false);
  const historyQuery = useDeviceSensors(deviceId, true);

  const [removing, setRemoving] = React.useState<Installation | null>(null);
  const [calibrating, setCalibrating] = React.useState<Installation | null>(null);

  if (deviceQuery.isLoading) return <LoadingState className="py-10" label="Memuat device…" />;
  if (deviceQuery.isError || !deviceQuery.data) return <ErrorState error={deviceQuery.error} title="Gagal memuat device" />;

  const device = deviceQuery.data;
  const history = (historyQuery.data ?? []).filter((i) => i.removed_at !== null);

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link href={`/manage/devices/${deviceId}/edit`} className="text-xs font-medium text-muted-foreground hover:text-foreground">
          ← Kembali ke edit device
        </Link>
        <h1 className="mt-1 text-xl font-semibold text-foreground">Sensor Terpasang — {device.code}</h1>
        <p className="text-sm text-muted-foreground">{device.name}</p>
      </div>

      <div className="grid gap-6 lg:grid-cols-[1.6fr_1fr]">
        <SectionCard title="Channel & Sensor Aktif" description="Sensor fisik yang sedang terpasang di setiap channel" contentClassName="p-0">
          {activeQuery.isLoading ? (
            <TableSkeleton rows={5} cols={4} />
          ) : activeQuery.isError ? (
            <div className="p-5">
              <ErrorState error={activeQuery.error} onRetry={() => activeQuery.refetch()} />
            </div>
          ) : !activeQuery.data || activeQuery.data.length === 0 ? (
            <div className="p-5">
              <EmptyState icon={Plug} message="Belum ada sensor terpasang" description="Pasang sensor melalui form di samping." />
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-left text-sm">
                <thead className="bg-muted text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                  <tr>
                    <th scope="col" className="px-4 py-2.5">Channel</th>
                    <th scope="col" className="px-3 py-2.5">Sensor</th>
                    <th scope="col" className="px-3 py-2.5">Dipasang</th>
                    <th scope="col" className="px-3 py-2.5 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {activeQuery.data.map((inst) => (
                    <tr key={inst.installation_id} className="hover:bg-muted/50">
                      <td className="px-4 py-3 font-mono text-xs">{inst.channel.channel_key}</td>
                      <td className="px-3 py-3">
                        <p className="font-mono text-xs font-medium">{inst.sensor.serial_number}</p>
                        <p className="text-[11px] text-muted-foreground">{inst.sensor.sensor_type}</p>
                      </td>
                      <td className="px-3 py-3 text-xs text-muted-foreground">{formatWib(inst.installed_at)}</td>
                      <td className="px-3 py-3">
                        <div className="flex justify-end gap-1.5">
                          <Button variant="outline" size="sm" onClick={() => setCalibrating(inst)}>
                            <SlidersHorizontal aria-hidden="true" className="size-3.5" />
                            Kalibrasi
                          </Button>
                          <Button variant="ghost" size="sm" className="text-error hover:bg-error-tint" onClick={() => setRemoving(inst)}>
                            <Unplug aria-hidden="true" className="size-3.5" />
                            Lepas
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </SectionCard>

        <SectionCard title="Pasang Sensor" description="Sensor yang belum terpasang di device mana pun">
          <InstallSensorForm deviceId={deviceId} onInstalled={() => historyQuery.refetch()} />
        </SectionCard>
      </div>

      <SectionCard title="Riwayat Pemasangan" description="Sensor yang pernah terpasang lalu dilepas dari device ini">
        {historyQuery.isLoading ? (
          <LoadingState />
        ) : history.length === 0 ? (
          <EmptyState icon={History} message="Belum ada riwayat pelepasan sensor" />
        ) : (
          <ol className="relative ml-2 border-l pl-5">
            {history.map((h) => (
              <li key={h.installation_id} className="mb-4 last:mb-0">
                <span aria-hidden="true" className="absolute -left-1.5 mt-1.5 size-3 rounded-full border-2 border-card bg-navy-300" />
                <p className="text-sm font-medium">
                  <span className="font-mono">{h.sensor.serial_number}</span>
                  <span className="text-muted-foreground"> di channel </span>
                  <span className="font-mono">{h.channel.channel_key}</span>
                </p>
                <p className="text-xs text-muted-foreground">
                  {formatWib(h.installed_at)} → {formatWib(h.removed_at)}
                </p>
                {h.removal_reason ? <p className="mt-0.5 text-xs text-muted-foreground italic">Alasan: {h.removal_reason}</p> : null}
              </li>
            ))}
          </ol>
        )}
      </SectionCard>

      <RemoveSensorDialog deviceId={deviceId} installation={removing} onClose={() => setRemoving(null)} />

      <Dialog
        open={calibrating !== null}
        onClose={() => setCalibrating(null)}
        title={`Kalibrasi Sensor ${calibrating?.sensor.serial_number ?? ''}`}
        description="value = raw × scale_factor + offset_value. Kalibrasi tidak berlaku mundur."
      >
        {calibrating ? (
          <CalibrationForm sensorId={calibrating.sensor.id} sensorSerial={calibrating.sensor.serial_number} />
        ) : null}
      </Dialog>
    </div>
  );
}

function RemoveSensorDialog({
  deviceId,
  installation,
  onClose,
}: {
  deviceId: number;
  installation: Installation | null;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const [reason, setReason] = React.useState('');
  const [removedAt, setRemovedAt] = React.useState('');

  const mutation = useMutation({
    mutationFn: async () =>
      apiSend('DELETE', `/devices/${deviceId}/sensors/${installation!.sensor.id}`, {
        reason,
        removed_at: removedAt ? new Date(`${removedAt}:00+07:00`).toISOString() : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['device-sensors', deviceId] });
      queryClient.invalidateQueries({ queryKey: ['sensors'] });
      queryClient.invalidateQueries({ queryKey: ['device', deviceId] });
      setReason('');
      setRemovedAt('');
      onClose();
    },
  });

  return (
    <Dialog
      open={installation !== null}
      onClose={() => {
        mutation.reset();
        onClose();
      }}
      title={`Lepas sensor ${installation?.sensor.serial_number ?? ''}?`}
      description={`Dari channel ${installation?.channel.channel_key ?? ''}. Bacaan lama tetap tersimpan di channel ini.`}
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" disabled={mutation.isPending || reason.trim().length === 0} onClick={() => mutation.mutate()}>
            {mutation.isPending ? 'Melepas…' : 'Lepas Sensor'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field label="Alasan" htmlFor="reason" hint="Wajib diisi, tercatat di riwayat.">
          <Input id="reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Contoh: drift, dipindah ke device lain" />
        </Field>
        <Field label="Waktu Lepas (WIB)" htmlFor="removed_at" hint="Kosongkan untuk waktu sekarang.">
          <Input id="removed_at" type="datetime-local" value={removedAt} onChange={(e) => setRemovedAt(e.target.value)} />
        </Field>
        {mutation.isError ? <ErrorState error={mutation.error} title="Gagal melepas sensor" /> : null}
      </div>
    </Dialog>
  );
}
