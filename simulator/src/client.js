import { randomUUID } from 'node:crypto';

export class ApiClient {
  constructor({ apiUrl, timeoutMs }) {
    this.base = `${apiUrl}/api/v1/ingest`;
    this.timeoutMs = timeoutMs;
  }

  telemetry(key, body) {
    return this.post('/telemetry', key, body);
  }

  batch(key, body) {
    return this.post('/telemetry/batch', key, body);
  }

  heartbeat(key, body) {
    return this.post('/heartbeat', key, body);
  }

  async post(path, key, body) {
    const requestId = randomUUID();
    try {
      const res = await fetch(this.base + path, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${key}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Request-Id': requestId,
        },
        body: JSON.stringify(body),
        signal: AbortSignal.timeout(this.timeoutMs),
      });

      const text = await res.text();
      let json = null;
      try {
        json = text ? JSON.parse(text) : null;
      } catch {
        json = null;
      }

      return {
        ok: res.status >= 200 && res.status < 300,
        status: res.status,
        retryAfterS: parseRetryAfter(res.headers.get('retry-after')),
        requestId: res.headers.get('x-request-id') || json?.request_id || requestId,
        body: json,
        raw: json ? null : text.slice(0, 300),
        networkError: null,
      };
    } catch (err) {
      return {
        ok: false,
        status: 0,
        retryAfterS: null,
        requestId,
        body: null,
        raw: null,
        networkError: err.name === 'TimeoutError' ? `timeout ${this.timeoutMs}ms` : err.cause?.code || err.message,
      };
    }
  }
}

function parseRetryAfter(value) {
  if (!value) return null;
  const s = Number(value);
  if (Number.isFinite(s)) return Math.max(0, s);
  const date = Date.parse(value);
  return Number.isNaN(date) ? null : Math.max(0, Math.ceil((date - Date.now()) / 1000));
}
