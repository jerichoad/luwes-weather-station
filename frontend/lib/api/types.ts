export type DeviceStatus = 'provisioned' | 'active' | 'maintenance' | 'decommissioned';
export type Connectivity = 'online' | 'stale' | 'offline' | 'never';
export type Interval = 'raw' | '1m' | '1h' | '1d';
export type Agg = 'avg' | 'min' | 'max' | 'sum';

export interface ErrorDetail {
  field?: string;
  code: string;
  message: string;
  suggested_interval?: Interval;
}

export interface ApiEnvelope<T, M = unknown> {
  data: T;
  meta?: M;
  request_id: string;
}

export interface ApiErrorBody {
  error: { code: string; message: string; details: ErrorDetail[] };
  request_id: string;
}

export interface PageMeta {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface LocationBrief {
  id: number;
  name: string;
}

export interface Location {
  id: number;
  code: string;
  name: string;
  address?: string | null;
  latitude: number | string;
  longitude: number | string;
  altitude_m: number | string | null;
}

export interface LatestPoint {
  value: number;
  unit: string;
  time: string;
}

export interface OverviewStation {
  id: number;
  code: string;
  name: string;
  location: string | null;
  status: DeviceStatus;
  connectivity: Connectivity;
  last_seen_at: string | null;
  latest: Record<string, LatestPoint>;
}

export interface Overview {
  totals: {
    devices: number;
    active: number;
    maintenance: number;
    provisioned: number;
    online: number;
    stale: number;
    offline: number;
    never: number;
  };
  stations: OverviewStation[];
  generated_at: string;
}

export interface DeviceListItem {
  id: number;
  code: string;
  name: string;
  status: DeviceStatus;
  connectivity: Connectivity;
  location: LocationBrief | null;
  last_seen_at: string | null;
  firmware_version: string | null;
}

export interface DeviceChannel {
  id: number;
  channel_key: string;
  label: string | null;
  sensor_type: { code: string; unit: string };
  current_sensor: { id: number; serial_number: string; installed_at: string | null } | null;
}

export interface DeviceDetail {
  id: number;
  code: string;
  name: string;
  status: DeviceStatus;
  allowed_transitions: DeviceStatus[];
  connectivity: Connectivity;
  location: Location | null;
  health: {
    last_seen_at: string | null;
    last_reading_at: string | null;
    battery_v: number | null;
    rssi: number | null;
    firmware_version: string | null;
  };
  channels: DeviceChannel[];
  created_at: string | null;
  updated_at: string | null;
}

export interface DeviceCreated {
  id: number;
  code: string;
  name: string;
  status: DeviceStatus;
  location: Location | null;
  credential: { api_key: string; key_prefix: string; notice: string };
  created_at: string | null;
}

export interface RotatedKey {
  api_key: string;
  key_prefix?: string;
  previous_keys_expire_at?: string | null;
}

export interface Health {
  connectivity: Connectivity;
  last_seen_at: string | null;
  silent_for_s: number | null;
  last_reading_at: string | null;
  battery_v: number | null;
  rssi: number | null;
  firmware_version: string | null;
  uptime_s: number | null;
  last_boot_at: string | null;
  clock_offset_s: number | null;
  channels_without_data: string[];
  battery_trend_24h: { interval: Interval; timestamps: number[]; values: (number | null)[] };
}

export interface LatestReading {
  channel_id: number;
  channel_key: string;
  label: string | null;
  sensor_type: string;
  unit: string;
  precision: number;
  value: number | null;
  raw_value: number | null;
  time: string | null;
  sensor: { id: number; serial_number: string } | null;
  rain_today_mm?: number;
  rain_today_unit?: string;
}

export interface Series {
  channel_id: number;
  channel_key: string;
  label: string | null;
  sensor_type: string;
  unit: string;
  precision: number;
  agg: Agg;
  values: (number | null)[];
}

export interface ReadingsData {
  device_id: number;
  interval: Interval;
  agg: string;
  timestamps: number[];
  series: Series[];
}

export interface ReadingsMeta {
  from: string;
  to: string;
  interval_seconds: number;
  points: number;
  max_points: number;
  next_cursor: number | null;
}

export interface Installation {
  installation_id: number;
  sensor: { id: number; serial_number: string; sensor_type: string };
  channel: { id: number; channel_key: string };
  installed_at: string | null;
  removed_at: string | null;
  removal_reason?: string | null;
}

export interface SensorItem {
  id: number;
  serial_number: string;
  sensor_type: string;
  manufacturer: string | null;
  model: string | null;
  retired_at: string | null;
}

export interface SensorType {
  id: number;
  code: string;
  name: string;
  unit: string;
  kind: 'gauge' | 'counter' | 'angle';
  min_value: number;
  max_value: number;
  precision: number;
}

export interface Calibration {
  id: number;
  scale_factor: number;
  offset_value: number;
  effective_from: string | null;
  note: string | null;
  created_at?: string | null;
  preview?: { raw: number; value: number } | null;
}
