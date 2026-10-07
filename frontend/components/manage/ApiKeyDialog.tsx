'use client';

import { AlertTriangle, Check, Copy } from 'lucide-react';
import * as React from 'react';
import { Button, Dialog } from '@/components/ui';

export function ApiKeyDialog({
  open,
  apiKey,
  title = 'API Key Berhasil Dibuat',
  warning = 'Simpan API key sekarang. Demi keamanan, key tidak akan ditampilkan lagi setelah dialog ini ditutup.',
  extra,
  onClose,
}: {
  open: boolean;
  apiKey: string | null;
  title?: string;
  warning?: string;
  extra?: React.ReactNode;
  onClose: () => void;
}) {
  const [copied, setCopied] = React.useState(false);

  async function copy() {
    if (!apiKey) return;
    try {
      await navigator.clipboard.writeText(apiKey);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  }

  return (
    <Dialog
      open={open && Boolean(apiKey)}
      onClose={onClose}
      title={title}
      footer={<Button onClick={onClose}>Saya Sudah Menyimpan Key</Button>}
    >
      <div className="flex flex-col gap-4">
        <div className="flex items-start gap-2.5 rounded-md bg-warning-tint p-3 text-xs text-warning">
          <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
          <p>{warning}</p>
        </div>

        <div className="flex flex-col gap-1.5">
          <label className="text-xs font-semibold text-muted-foreground">API Key</label>
          <div className="flex items-center gap-2">
            <input
              type="text"
              readOnly
              value={apiKey ?? ''}
              className="h-9 flex-1 rounded-md border bg-muted/60 px-3 font-mono text-xs text-foreground select-all"
            />
            <Button variant="secondary" size="sm" onClick={copy}>
              {copied ? <Check aria-hidden="true" className="size-3.5 text-success" /> : <Copy aria-hidden="true" className="size-3.5" />}
              {copied ? 'Tersalin' : 'Salin'}
            </Button>
          </div>
        </div>

        {extra}
      </div>
    </Dialog>
  );
}
