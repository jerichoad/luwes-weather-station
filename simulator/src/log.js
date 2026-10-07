let verbose = false;

export function setVerbose(v) {
  verbose = v;
}

function line(level, scope, msg) {
  const out = `${new Date().toISOString()} ${level.padEnd(5)} [${scope}] ${msg}`;
  if (level === 'ERROR' || level === 'WARN') console.error(out);
  else console.log(out);
}

export const log = {
  debug: (scope, msg) => verbose && line('DEBUG', scope, msg),
  info: (scope, msg) => line('INFO', scope, msg),
  warn: (scope, msg) => line('WARN', scope, msg),
  error: (scope, msg) => line('ERROR', scope, msg),
};

export function summarizeWarnings(warnings) {
  if (!Array.isArray(warnings) || warnings.length === 0) return '';
  return ` warnings=${[...new Set(warnings.map((w) => w.code))].join(',')}`;
}
