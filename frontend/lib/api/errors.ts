import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';
import type { ErrorDetail } from './types';

export class ApiError extends Error {
  status: number;
  code: string;
  details: ErrorDetail[];
  requestId: string | null;

  constructor(status: number, code: string, message: string, details: ErrorDetail[] = [], requestId: string | null = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details;
    this.requestId = requestId;
  }
}

/**
 * Petakan `error.details[].field` dari backend ke `setError()` React Hook Form.
 * Mengembalikan true jika minimal satu field cocok; sisanya ditampilkan sebagai
 * error umum oleh pemanggil.
 */
export function applyFieldErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  knownFields: ReadonlyArray<Path<T>>,
): boolean {
  if (!(error instanceof ApiError)) return false;
  let matched = false;
  for (const d of error.details) {
    if (d.field && (knownFields as readonly string[]).includes(d.field)) {
      setError(d.field as Path<T>, { type: 'server', message: d.message });
      matched = true;
    }
  }
  return matched;
}
