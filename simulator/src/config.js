const SCENARIOS = [
  'normal',
  'offline',
  'duplicate',
  'restart',
  'clock-future',
  'invalid',
  'unknown-device',
  'big-batch',
];

function int(name, fallback, min = 0) {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return fallback;
  const n = Number.parseInt(raw, 10);
  if (!Number.isFinite(n) || n < min) {
    throw new Error(`${name} harus integer >= ${min}, diterima: "${raw}"`);
  }
  return n;
}

function bool(name, fallback) {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(raw.toLowerCase());
}

export function parseDevices(raw) {
  return (raw ?? '')
    .split(';')
    .map((s) => s.trim())
    .filter(Boolean)
    .map((pair) => {
      const i = pair.indexOf('=');
      if (i <= 0 || i === pair.length - 1) {
        throw new Error(`Format SIM_DEVICES tidak valid pada "${pair}", gunakan KODE=key;KODE=key`);
      }
      return { code: pair.slice(0, i).trim(), key: pair.slice(i + 1).trim() };
    });
}

export function loadConfig() {
  const scenario = (process.env.SIM_SCENARIO || 'normal').toLowerCase();
  if (!SCENARIOS.includes(scenario)) {
    throw new Error(`SIM_SCENARIO tidak dikenal: "${scenario}". Pilihan: ${SCENARIOS.join(', ')}`);
  }

  const devices = parseDevices(process.env.SIM_DEVICES || process.env.SEED_DEVICE_KEYS);
  if (devices.length === 0 && scenario !== 'unknown-device') {
    throw new Error('SIM_DEVICES kosong. Format: WS-GRT-001=key;WS-DPK-001=key');
  }

  const target = process.env.SIM_TARGET || devices[0]?.code;
  if (target && devices.length > 0 && !devices.some((d) => d.code === target)) {
    throw new Error(`SIM_TARGET "${target}" tidak ada di SIM_DEVICES`);
  }

  return {
    apiUrl: (process.env.API_URL || 'http://localhost:8080').replace(/\/+$/, ''),
    devices,
    scenario,
    target,
    intervalS: int('SIM_INTERVAL_S', 60, 1),
    heartbeatS: int('SIM_HEARTBEAT_S', 300, 1),
    offlineMinutes: int('SIM_OFFLINE_MINUTES', 180, 1),
    offlineRealtime: bool('SIM_OFFLINE_REALTIME', false),
    firmware: process.env.SIM_FW || '1.4.2',
    unknownDevice: process.env.SIM_UNKNOWN_DEVICE || 'WS-XXX-999',
    bufferMax: int('SIM_BUFFER_MAX', 20000, 1),
    batchMax: int('SIM_BATCH_MAX', 500, 1),
    timeoutMs: int('SIM_TIMEOUT_MS', 15000, 100),
    verbose: bool('SIM_VERBOSE', false),
  };
}

export { SCENARIOS };
