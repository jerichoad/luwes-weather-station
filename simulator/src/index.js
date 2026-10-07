import { loadConfig } from './config.js';
import { ApiClient } from './client.js';
import { Device } from './device.js';
import { log, setVerbose } from './log.js';
import { scenarios } from './scenarios.js';

async function main() {
  const cfg = loadConfig();
  setVerbose(cfg.verbose);

  const client = new ApiClient(cfg);
  const devices = cfg.devices.map((d) => new Device(d, client, cfg));

  log.info('sim', `API_URL=${cfg.apiUrl} scenario=${cfg.scenario} devices=${cfg.devices.map((d) => d.code).join(',') || '-'}`);

  const controller = new AbortController();
  const stop = (sig) => {
    if (controller.signal.aborted) process.exit(130);
    log.info('sim', `${sig} diterima, berhenti...`);
    controller.abort();
  };
  process.on('SIGINT', () => stop('SIGINT'));
  process.on('SIGTERM', () => stop('SIGTERM'));

  await scenarios[cfg.scenario](devices, cfg, controller.signal, client);

  for (const d of devices) {
    const s = d.stats;
    if (s.accepted + s.duplicate + s.rejected + s.failed + s.dropped > 0 || d.buffer.length > 0) {
      log.info(d.code, `ringkasan accepted=${s.accepted} duplicate=${s.duplicate} rejected=${s.rejected} failed=${s.failed} dropped=${s.dropped} buffer=${d.buffer.length}`);
    }
  }
}

main().catch((err) => {
  log.error('sim', err.message);
  process.exit(1);
});
