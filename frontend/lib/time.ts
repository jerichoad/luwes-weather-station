const wibDateTime = new Intl.DateTimeFormat('id-ID', {
  timeZone: 'Asia/Jakarta',
  dateStyle: 'medium',
  timeStyle: 'short',
});

const wibTimeOnly = new Intl.DateTimeFormat('id-ID', {
  timeZone: 'Asia/Jakarta',
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
});

const wibDateOnly = new Intl.DateTimeFormat('id-ID', {
  timeZone: 'Asia/Jakarta',
  day: 'numeric',
  month: 'short',
  year: 'numeric',
});

export function formatWib(t: string | number | Date | null | undefined): string {
  if (t === null || t === undefined) return '—';
  const date = t instanceof Date ? t : new Date(t);
  if (Number.isNaN(date.getTime())) return '—';
  return `${wibDateTime.format(date)} WIB`;
}

export function formatWibTime(t: string | number | Date | null | undefined): string {
  if (t === null || t === undefined) return '—';
  const date = t instanceof Date ? t : new Date(t);
  if (Number.isNaN(date.getTime())) return '—';
  return wibTimeOnly.format(date);
}

export function formatWibDate(t: string | number | Date | null | undefined): string {
  if (t === null || t === undefined) return '—';
  const date = t instanceof Date ? t : new Date(t);
  if (Number.isNaN(date.getTime())) return '—';
  return wibDateOnly.format(date);
}

/** "3 menit lalu", "2 jam lalu", dsb — dihitung dari waktu absolut (bukan timezone browser). */
export function relativeTime(t: string | number | Date | null | undefined, now: Date = new Date()): string {
  if (t === null || t === undefined) return 'tidak pernah';
  const date = t instanceof Date ? t : new Date(t);
  if (Number.isNaN(date.getTime())) return 'tidak pernah';

  const diffMs = now.getTime() - date.getTime();
  const diffS = Math.floor(diffMs / 1000);

  if (diffS < 0) return 'baru saja';
  if (diffS < 60) return 'baru saja';
  if (diffS < 3600) {
    const m = Math.floor(diffS / 60);
    return `${m} menit lalu`;
  }
  if (diffS < 86400) {
    const h = Math.floor(diffS / 3600);
    return `${h} jam lalu`;
  }
  const d = Math.floor(diffS / 86400);
  return `${d} hari lalu`;
}

/** Dua baris untuk kartu: "3 menit lalu" + "10:42 WIB" */
export function formatRelativeWithWib(t: string | number | Date | null | undefined, now?: Date): string {
  if (t === null || t === undefined) return 'Belum pernah mengirim data';
  return `${relativeTime(t, now)} · ${formatWibTime(t)} WIB`;
}
