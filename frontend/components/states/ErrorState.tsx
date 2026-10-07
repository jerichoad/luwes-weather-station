'use client';

import { AlertTriangle, Check, Copy, RotateCw } from 'lucide-react';
import * as React from 'react';
import { ApiError } from '@/lib/api/errors';
import { cn } from '@/lib/utils';

function describe(error: unknown): { message: string; code: string | null; requestId: string | null } {
  if (error instanceof ApiError) {
    return { message: error.message, code: error.code, requestId: error.requestId };
  }
  if (error instanceof Error) {
    return { message: error.message, code: null, requestId: null };
  }
  return { message: 'Terjadi kesalahan.', code: null, requestId: null };
}

export function RequestIdChip({ requestId }: { requestId: string }) {
  const [copied, setCopied] = React.useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(requestId);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      setCopied(false);
    }
  }

  return (
    <button
      type="button"
      onClick={copy}
      title="Salin request_id untuk dicari di log backend"
      className="inline-flex items-center gap-1.5 rounded bg-white/70 px-2 py-0.5 font-mono text-[11px] text-error ring-1 ring-error/20 hover:bg-white focus-visible:outline-2 focus-visible:outline-ring"
    >
      {copied ? <Check aria-hidden="true" className="size-3" /> : <Copy aria-hidden="true" className="size-3" />}
      <span className="sr-only">Salin request_id</span>
      {requestId}
    </button>
  );
}

export function ErrorState({
  error,
  onRetry,
  title = 'Gagal memuat data',
  className,
}: {
  error: unknown;
  onRetry?: () => void;
  title?: string;
  className?: string;
}) {
  const { message, code, requestId } = describe(error);

  return (
    <div
      role="alert"
      className={cn('flex flex-col gap-3 rounded-md border border-error/20 bg-error-tint/60 px-5 py-5 text-sm text-error', className)}
    >
      <div className="flex items-start gap-2.5">
        <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
        <div className="min-w-0 space-y-1">
          <p className="font-semibold">{title}</p>
          <p className="text-error/90">{message}</p>
          <div className="flex flex-wrap items-center gap-2 pt-1">
            {code ? <span className="rounded bg-error/10 px-1.5 py-0.5 font-mono text-[11px] font-semibold">{code}</span> : null}
            {requestId ? <RequestIdChip requestId={requestId} /> : null}
          </div>
        </div>
      </div>
      {onRetry ? (
        <div>
          <button
            type="button"
            onClick={onRetry}
            className="inline-flex items-center gap-1.5 rounded-md bg-error px-3 py-1.5 text-xs font-semibold text-white hover:bg-error/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-error"
          >
            <RotateCw aria-hidden="true" className="size-3.5" />
            Coba lagi
          </button>
        </div>
      ) : null}
    </div>
  );
}

/** Banner kecil saat polling gagal tapi data lama masih ada. */
export function StaleDataBanner({ onRetry }: { onRetry: () => void }) {
  return (
    <div role="status" className="flex items-center justify-between gap-3 rounded-md bg-warning-tint px-3 py-1.5 text-xs text-warning">
      <span>Gagal memperbarui — menampilkan data terakhir.</span>
      <button type="button" onClick={onRetry} className="font-semibold underline underline-offset-2 hover:no-underline">
        Coba lagi
      </button>
    </div>
  );
}
