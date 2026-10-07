import { ApiError } from './errors';
import type { ApiEnvelope, ApiErrorBody } from './types';

function buildUrl(path: string, params?: Record<string, string | number | boolean | undefined | null>): string {
  const cleanPath = path.startsWith('/') ? path : `/${path}`;
  const prefix = '/api/backend';
  const url = new URL(`${prefix}${cleanPath}`, typeof window !== 'undefined' ? window.location.origin : 'http://localhost:3000');
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null && v !== '') {
        url.searchParams.set(k, String(v));
      }
    }
  }
  return url.pathname + url.search;
}

async function handleResponse<T, M = unknown>(res: Response): Promise<{ data: T; meta?: M; request_id: string }> {
  const text = await res.text();
  let json: unknown = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    throw new ApiError(res.status, 'MALFORMED_JSON', 'Format response bukan JSON valid.', [], res.headers.get('x-request-id'));
  }

  if (!res.ok) {
    const errBody = json as ApiErrorBody | null;
    const code = errBody?.error?.code ?? `HTTP_${res.status}`;
    const msg = errBody?.error?.message ?? res.statusText ?? 'Request gagal';
    const details = errBody?.error?.details ?? [];
    const reqId = errBody?.request_id ?? res.headers.get('x-request-id');
    throw new ApiError(res.status, code, msg, details, reqId);
  }

  return json as ApiEnvelope<T, M>;
}

export async function apiGet<T, M = unknown>(
  path: string,
  params?: Record<string, string | number | boolean | undefined | null>,
): Promise<{ data: T; meta?: M; request_id: string }> {
  const url = buildUrl(path, params);
  const res = await fetch(url, {
    method: 'GET',
    headers: { Accept: 'application/json' },
  });
  return handleResponse<T, M>(res);
}

export async function apiSend<T, M = unknown>(
  method: 'POST' | 'PATCH' | 'PUT' | 'DELETE',
  path: string,
  body?: unknown,
): Promise<{ data: T; meta?: M; request_id: string }> {
  const url = buildUrl(path);
  const res = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  return handleResponse<T, M>(res);
}
