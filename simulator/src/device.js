import { WeatherState } from './weather.js';
import { log, summarizeWarnings } from './log.js';

const BACKOFF_BASE_S = 2;
const BACKOFF_MAX_S = 300;

export class Device {
  constructor({ code, key }, client, cfg) {
    this.code = code;
    this.key = key;
    this.client = client;
    this.cfg = cfg;
    this.state = new WeatherState(code, cfg.intervalS);
    this.buffer = [];
    this.failures = 0;
    this.retryAt = 0;
    this.lastHeartbeatAt = 0;
    this.disabled = false;
    this.stats = { accepted: 0, duplicate: 0, rejected: 0, failed: 0, dropped: 0 };
  }

  get fw() {
    return this.cfg.firmware;
  }

  envelope(extra) {
    return { device_id: this.code, fw: this.fw, ...extra };
  }

  enqueue(packet) {
    this.buffer.push(packet);
    if (this.buffer.length > this.cfg.bufferMax) {
      const over = this.buffer.length - this.cfg.bufferMax;
      this.buffer.splice(0, over);
      this.stats.dropped += over;
      log.warn(this.code, `buffer penuh, ${over} paket tertua dibuang`);
    }
  }

  canSend(now = Date.now()) {
    return !this.disabled && now >= this.retryAt;
  }

  async tick(tsSec = Math.floor(Date.now() / 1000)) {
    if (this.disabled) return;
    const packet = this.state.nextPacket(tsSec);

    if (this.buffer.length === 0 && this.canSend()) {
      await this.sendSingle(packet);
    } else {
      this.enqueue(packet);
      if (this.canSend()) await this.flush();
      else log.debug(this.code, `offline/backoff, buffer=${this.buffer.length}`);
    }

    if (tsSec - this.lastHeartbeatAt >= this.cfg.heartbeatS) {
      await this.heartbeat(tsSec);
    }
  }

  async sendSingle(packet) {
    const res = await this.client.telemetry(this.key, this.envelope(packet));

    if (res.ok) {
      this.onSuccess();
      const d = res.body?.data ?? {};
      this.count(d.status);
      log.info(this.code, `telemetry seq=${packet.seq} ${res.status} ${d.status}${summarizeWarnings(d.warnings)}`);
      return res;
    }

    if (this.isPermanent(res)) {
      this.stats.rejected += 1;
      log.warn(this.code, `telemetry seq=${packet.seq} ditolak ${describe(res)}`);
      return res;
    }

    this.enqueue(packet);
    this.onFailure(res, 'telemetry');
    return res;
  }

  async flush() {
    while (this.buffer.length > 0 && this.canSend()) {
      const chunk = this.buffer.slice(0, this.cfg.batchMax);
      const res = await this.client.batch(this.key, this.envelope({ batch: chunk }));

      if (res.status === 422 && res.body?.error?.code === 'BATCH_TOO_LARGE') {
        this.cfg.batchMax = Math.max(1, Math.floor(chunk.length / 2));
        log.warn(this.code, `BATCH_TOO_LARGE, ukuran batch diturunkan ke ${this.cfg.batchMax}`);
        continue;
      }

      const items = res.body?.data?.items;
      if ((res.ok || res.status === 422) && Array.isArray(items)) {
        this.onSuccess();
        const done = new Set();
        for (const it of items) {
          if (it.status === 'accepted' || it.status === 'duplicate') done.add(it.index);
          else if (it.status === 'rejected') {
            done.add(it.index);
            log.warn(this.code, `batch item ${it.index} (seq=${it.seq}) ditolak ${it.error?.code ?? ''}`);
          }
        }
        this.buffer = [...chunk.filter((_, i) => !done.has(i)), ...this.buffer.slice(chunk.length)];
        const s = res.body.data.summary ?? {};
        this.stats.accepted += s.accepted ?? 0;
        this.stats.duplicate += s.duplicate ?? 0;
        this.stats.rejected += s.rejected ?? 0;
        log.info(
          this.code,
          `batch ${chunk.length} ${res.status} accepted=${s.accepted} duplicate=${s.duplicate} rejected=${s.rejected} sisa buffer=${this.buffer.length}`,
        );
        if (done.size === 0) break;
        continue;
      }

      if (this.isPermanent(res)) {
        if (!this.disabled) {
          this.buffer = this.buffer.slice(chunk.length);
          this.stats.rejected += chunk.length;
          log.error(this.code, `batch ditolak permanen ${describe(res)}, ${chunk.length} paket dibuang`);
        }
        return res;
      }

      this.onFailure(res, 'batch');
      return res;
    }
    return null;
  }

  async heartbeat(tsSec = Math.floor(Date.now() / 1000), uptime = this.state.uptime(tsSec)) {
    this.lastHeartbeatAt = tsSec;
    const res = await this.client.heartbeat(
      this.key,
      this.envelope({ ts: tsSec, battery_v: Math.round(this.state.battery * 100) / 100, rssi: this.state.rssi(), uptime_s: uptime }),
    );
    if (res.ok) log.info(this.code, `heartbeat uptime=${uptime}s ${res.status} ${res.body?.data?.status}`);
    else log.warn(this.code, `heartbeat gagal ${describe(res)}`);
    return res;
  }

  isPermanent(res) {
    if (res.status === 401 || res.status === 403) {
      this.disabled = true;
      log.error(this.code, `credential ditolak ${describe(res)}, device dihentikan`);
      return true;
    }
    return res.status === 400 || res.status === 413 || res.status === 422;
  }

  onSuccess() {
    this.failures = 0;
    this.retryAt = 0;
  }

  onFailure(res, what) {
    this.failures += 1;
    this.stats.failed += 1;
    const backoff = Math.min(BACKOFF_MAX_S, BACKOFF_BASE_S * 2 ** (this.failures - 1));
    const waitS = res.retryAfterS != null ? Math.max(res.retryAfterS, 1) : backoff + Math.random();
    this.retryAt = Date.now() + waitS * 1000;
    log.warn(this.code, `${what} gagal ${describe(res)}, retry dalam ${waitS.toFixed(1)}s, buffer=${this.buffer.length}`);
  }

  count(status) {
    if (status === 'accepted') this.stats.accepted += 1;
    else if (status === 'duplicate') this.stats.duplicate += 1;
  }
}

export function describe(res) {
  if (res.networkError) return `[network: ${res.networkError}]`;
  const code = res.body?.error?.code;
  return `[${res.status}${code ? ` ${code}` : ''} req=${res.requestId}]`;
}
