import { Device, describe } from './device.js';
import { log, summarizeWarnings } from './log.js';

const nowSec = () => Math.floor(Date.now() / 1000);
const alignedNow = (intervalS) => Math.floor(nowSec() / intervalS) * intervalS;

export function sleep(ms, signal) {
  return new Promise((resolve) => {
    if (signal?.aborted) return resolve();
    const t = setTimeout(resolve, ms);
    signal?.addEventListener('abort', () => {
      clearTimeout(t);
      resolve();
    }, { once: true });
  });
}

function pick(devices, cfg) {
  return devices.find((d) => d.code === cfg.target) ?? devices[0];
}

function show(label, res) {
  const body = res.body ?? res.raw ?? { network_error: res.networkError };
  log.info('result', `${label} -> HTTP ${res.status}\n${JSON.stringify(body, null, 2)}`);
}

async function normal(devices, cfg, signal) {
  log.info('sim', `normal: ${devices.length} device, interval ${cfg.intervalS}s, heartbeat ${cfg.heartbeatS}s`);
  while (!signal.aborted) {
    const ts = alignedNow(cfg.intervalS);
    await Promise.all(devices.map((d) => d.tick(ts)));
    const waitMs = (ts + cfg.intervalS) * 1000 - Date.now();
    await sleep(Math.max(250, waitMs), signal);
  }
}

async function offline(devices, cfg, signal) {
  const dev = pick(devices, cfg);
  const total = Math.max(1, Math.round((cfg.offlineMinutes * 60) / cfg.intervalS));
  log.info(dev.code, `offline: menyimpan ${total} paket (${cfg.offlineMinutes} menit) di buffer`);

  if (cfg.offlineRealtime) {
    for (let i = 0; i < total && !signal.aborted; i++) {
      dev.enqueue(dev.state.nextPacket(alignedNow(cfg.intervalS)));
      log.debug(dev.code, `buffer=${dev.buffer.length}`);
      await sleep(cfg.intervalS * 1000, signal);
    }
  } else {
    const end = alignedNow(cfg.intervalS) - cfg.intervalS;
    const start = end - (total - 1) * cfg.intervalS;
    for (let i = 0; i < total; i++) dev.enqueue(dev.state.nextPacket(start + i * cfg.intervalS));
  }

  log.info(dev.code, `kembali online, flush ${dev.buffer.length} paket via /batch (maks ${cfg.batchMax}/request)`);
  while (dev.buffer.length > 0 && !signal.aborted && !dev.disabled) {
    if (!dev.canSend()) {
      await sleep(dev.retryAt - Date.now(), signal);
      continue;
    }
    const res = await dev.flush();
    if (res && !res.ok && dev.isPermanent(res)) break;
  }
  await dev.heartbeat(nowSec());
  log.info(dev.code, `selesai, sisa buffer=${dev.buffer.length}`);
}

async function duplicate(devices, cfg) {
  const dev = pick(devices, cfg);
  const body = dev.envelope(dev.state.nextPacket(nowSec()));
  for (let i = 1; i <= 3; i++) {
    const res = await dev.client.telemetry(dev.key, body);
    log.info(dev.code, `kiriman #${i}: HTTP ${res.status} status=${res.body?.data?.status ?? describe(res)}`);
    if (i === 3) show('duplicate #3', res);
  }
}

async function restart(devices, cfg) {
  const dev = pick(devices, cfg);
  const base = alignedNow(cfg.intervalS) - 2 * cfg.intervalS;

  dev.state.seq = 4000;
  dev.state.rainCounter = 1043;
  const before = dev.state.nextPacket(base);
  const r1 = await dev.client.telemetry(dev.key, dev.envelope(before));
  log.info(dev.code, `sebelum reboot seq=${before.seq} rain_counter=${dev.state.rainCounter}: HTTP ${r1.status} ${r1.body?.data?.status ?? describe(r1)}`);

  dev.state.reboot(base + cfg.intervalS);
  dev.state.rainCounter = 4;
  const after = dev.state.nextPacket(base + cfg.intervalS);
  const r2 = await dev.client.telemetry(dev.key, dev.envelope(after));
  const rain = after.readings.find((r) => r.s === 'rain_counter').v;
  log.info(dev.code, `setelah reboot seq=${after.seq} rain_counter=${rain}: HTTP ${r2.status} ${r2.body?.data?.status ?? describe(r2)}`);

  await dev.heartbeat(base + cfg.intervalS + 5, 5);
  show('restart', r2);
}

async function clockFuture(devices, cfg) {
  const dev = pick(devices, cfg);
  const packet = dev.state.nextPacket(nowSec() + 2 * 3600);
  const res = await dev.client.telemetry(dev.key, dev.envelope(packet));
  log.info(dev.code, `ts +2 jam: HTTP ${res.status}${summarizeWarnings(res.body?.data?.warnings)}`);
  show('clock-future', res);
}

async function invalid(devices, cfg) {
  const dev = pick(devices, cfg);
  const packet = dev.state.nextPacket(nowSec());
  packet.readings = packet.readings
    .filter((r) => r.s !== 'solar_rad')
    .map((r) => (r.s === 'temp_air' ? { ...r, v: -999 } : r.s === 'humidity' ? { ...r, v: 150 } : r));
  const res = await dev.client.telemetry(dev.key, dev.envelope(packet));
  log.info(dev.code, `temp_air=-999, humidity=150, solar_rad hilang: HTTP ${res.status}${summarizeWarnings(res.body?.data?.warnings)}`);
  show('invalid', res);
}

async function unknownDevice(devices, cfg, _signal, client) {
  const key = devices[0]?.key ?? 'wsk_invalid_key';
  const fake = new Device({ code: cfg.unknownDevice, key }, client, cfg);
  const res = await client.telemetry(key, fake.envelope(fake.state.nextPacket(nowSec())));
  log.info(fake.code, `device tidak terdaftar: HTTP ${res.status} ${res.body?.error?.code ?? describe(res)}`);
  show('unknown-device', res);
}

async function bigBatch(devices, cfg) {
  const dev = pick(devices, cfg);
  const build = (n, endTs) => {
    const start = endTs - (n - 1) * cfg.intervalS;
    return Array.from({ length: n }, (_, i) => dev.state.nextPacket(start + i * cfg.intervalS));
  };

  const end = alignedNow(cfg.intervalS) - cfg.intervalS;

  const t0 = Date.now();
  const r500 = await dev.client.batch(dev.key, dev.envelope({ batch: build(500, end - 600 * cfg.intervalS) }));
  log.info(dev.code, `batch 500: HTTP ${r500.status} ${JSON.stringify(r500.body?.data?.summary ?? r500.body?.error ?? describe(r500))} (${Date.now() - t0}ms)`);

  const r501 = await dev.client.batch(dev.key, dev.envelope({ batch: build(501, end) }));
  log.info(dev.code, `batch 501: HTTP ${r501.status} ${r501.body?.error?.code ?? describe(r501)}`);
  show('big-batch 501', r501);
}

export const scenarios = {
  normal,
  offline,
  duplicate,
  restart,
  'clock-future': clockFuture,
  invalid,
  'unknown-device': unknownDevice,
  'big-batch': bigBatch,
};
