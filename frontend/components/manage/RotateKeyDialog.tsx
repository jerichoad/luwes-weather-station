'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import { KeyRound } from 'lucide-react';
import * as React from 'react';
import { ApiKeyDialog } from '@/components/manage/ApiKeyDialog';
import { ErrorState } from '@/components/states/ErrorState';
import { Button, Dialog } from '@/components/ui';
import { apiSend } from '@/lib/api/client';
import type { RotatedKey } from '@/lib/api/types';
import { formatWib } from '@/lib/time';

export function RotateKeyDialog({ deviceId, deviceCode }: { deviceId: number; deviceCode: string }) {
  const queryClient = useQueryClient();
  const [confirmOpen, setConfirmOpen] = React.useState(false);
  const [result, setResult] = React.useState<RotatedKey | null>(null);

  const mutation = useMutation({
    mutationFn: async () => (await apiSend<RotatedKey>('POST', `/devices/${deviceId}/credentials/rotate`)).data,
    onSuccess: (data) => {
      setConfirmOpen(false);
      setResult(data);
      queryClient.invalidateQueries({ queryKey: ['device', deviceId] });
    },
  });

  return (
    <>
      <Button variant="secondary" size="sm" onClick={() => setConfirmOpen(true)}>
        <KeyRound aria-hidden="true" className="size-3.5" />
        Rotasi API Key
      </Button>

      <Dialog
        open={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        title={`Rotasi key untuk ${deviceCode}?`}
        description="Key lama akan tetap berlaku selama masa tenggang (grace period) agar device sempat diperbarui, lalu kedaluwarsa otomatis."
        footer={
          <>
            <Button variant="outline" onClick={() => setConfirmOpen(false)}>
              Batal
            </Button>
            <Button variant="danger" onClick={() => mutation.mutate()} disabled={mutation.isPending}>
              {mutation.isPending ? 'Memproses…' : 'Ya, Rotasi Sekarang'}
            </Button>
          </>
        }
      >
        {mutation.isError ? <ErrorState error={mutation.error} title="Gagal melakukan rotasi" /> : null}
      </Dialog>

      <ApiKeyDialog
        open={result !== null}
        apiKey={result?.api_key ?? null}
        title="Key Baru Berhasil Dibuat"
        extra={
          result?.previous_keys_expire_at ? (
            <p className="rounded-md bg-info-tint px-3 py-2 text-xs text-info">
              Key lama kedaluwarsa pada <strong>{formatWib(result.previous_keys_expire_at)}</strong>.
            </p>
          ) : null
        }
        onClose={() => setResult(null)}
      />
    </>
  );
}
