const WIB_OFFSET_S = 7 * 3600;

const PROFILES = {
  'WS-GRT-001': { temp: 19.5, pressure: 930.0 },
  'WS-DPK-001': { temp: 27.5, pressure: 1005.0 },
  'WS-BGR-001': { temp: 24.5, pressure: 980.0 },
};

const noise = (amp) => (Math.random() * 2 - 1) * amp;
const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
const round = (v, d = 2) => Math.round(v * 10 ** d) / 10 ** d;
const randInt = (lo, hi) => lo + Math.floor(Math.random() * (hi - lo + 1));

export function hourWib(tsSec) {
  return (((tsSec + WIB_OFFSET_S) % 86400) + 86400) % 86400 / 3600;
}

export class WeatherState {
  constructor(code, intervalS) {
    this.code = code;
    this.intervalS = intervalS;
    this.profile = PROFILES[code] ?? { temp: 25.0, pressure: 1000.0 };
    this.seq = 0;
    this.rainCounter = 0;
    this.windSpeed = 2.0;
    this.windDir = randInt(0, 359);
    this.battery = 3.95;
    this.bootAt = Math.floor(Date.now() / 1000);
  }

  reboot(atSec = Math.floor(Date.now() / 1000)) {
    this.seq = 0;
    this.rainCounter = 0;
    this.bootAt = atSec;
  }

  uptime(atSec = Math.floor(Date.now() / 1000)) {
    return Math.max(0, atSec - this.bootAt);
  }

  rssi() {
    return randInt(-85, -68);
  }

  nextPacket(tsSec) {
    const p = this.profile;
    const h = hourWib(tsSec);
    const stepMin = this.intervalS / 60;

    const temp = p.temp + 5.0 * Math.sin((2 * Math.PI * (h - 8)) / 24) + noise(0.3);
    const humidity = clamp(78.0 - 2.8 * (temp - p.temp) + noise(2.0), 35.0, 99.0);
    const pressure = p.pressure + 1.2 * Math.sin((2 * Math.PI * h) / 12) + noise(1.0);
    const solar = h >= 6 && h <= 18 ? Math.max(0, 850 * Math.sin((Math.PI * (h - 6)) / 12) + noise(3.0)) : 0;

    this.windSpeed = clamp(this.windSpeed + noise(0.4), 0.2, 14.0);
    this.windDir = (Math.round(this.windDir + noise(12)) + 360) % 360;

    if (h >= 14 && h <= 17 && Math.random() < Math.min(1, 0.35 * stepMin)) {
      this.rainCounter += randInt(1, 4);
    }

    this.battery = h >= 7 && h <= 17
      ? Math.min(4.18, this.battery + 0.0003 * stepMin)
      : Math.max(3.65, this.battery - 0.0002 * stepMin);

    this.seq += 1;

    return {
      ts: tsSec,
      seq: this.seq,
      battery_v: round(this.battery),
      rssi: this.rssi(),
      readings: [
        { s: 'temp_air', v: round(temp) },
        { s: 'humidity', v: round(humidity) },
        { s: 'pressure', v: round(pressure) },
        { s: 'wind_speed', v: round(this.windSpeed) },
        { s: 'wind_dir', v: this.windDir },
        { s: 'rain_counter', v: this.rainCounter },
        { s: 'solar_rad', v: round(solar) },
      ],
    };
  }
}
