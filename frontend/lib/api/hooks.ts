import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiGet } from './client';
import type {
  Calibration,
  DeviceDetail,
  DeviceListItem,
  Health,
  Installation,
  LatestReading,
  Location,
  Overview,
  PageMeta,
  ReadingsData,
  ReadingsMeta,
  SensorItem,
  SensorType,
} from './types';

export function useOverview(pollMs: number | false) {
  return useQuery({
    queryKey: ['overview'],
    queryFn: async () => (await apiGet<Overview>('/dashboard/overview')).data,
    refetchInterval: pollMs,
  });
}

export function useDevices(params: { status?: string; location_id?: number; q?: string; page?: number; per_page?: number }) {
  return useQuery({
    queryKey: ['devices', params],
    queryFn: async () => {
      const res = await apiGet<DeviceListItem[], PageMeta>('/devices', params as Record<string, string | number | undefined>);
      return { items: res.data, meta: res.meta! };
    },
  });
}

export function useDevice(id: number | undefined) {
  return useQuery({
    queryKey: ['device', id],
    queryFn: async () => (await apiGet<DeviceDetail>(`/devices/${id}`)).data,
    enabled: id !== undefined,
  });
}

export function useDeviceLatest(id: number | undefined, pollMs: number | false) {
  return useQuery({
    queryKey: ['device-latest', id],
    queryFn: async () => (await apiGet<LatestReading[]>(`/devices/${id}/readings/latest`)).data,
    enabled: id !== undefined,
    refetchInterval: pollMs,
  });
}

export function useDeviceHealth(id: number | undefined, pollMs: number | false) {
  return useQuery({
    queryKey: ['device-health', id],
    queryFn: async () => (await apiGet<Health>(`/devices/${id}/health`)).data,
    enabled: id !== undefined,
    refetchInterval: pollMs,
  });
}

export function useReadings(
  params: {
    device_id: number;
    sensor_type: string;
    from: string;
    to: string;
    interval?: string;
    agg?: string;
  } | null,
  pollMs: number | false,
) {
  return useQuery({
    queryKey: ['readings', params],
    queryFn: async () => {
      const res = await apiGet<ReadingsData, ReadingsMeta>('/readings', params as Record<string, string | number | undefined>);
      return { data: res.data, meta: res.meta! };
    },
    enabled: params !== null,
    refetchInterval: pollMs,
    placeholderData: keepPreviousData,
  });
}

export function useLocations() {
  return useQuery({
    queryKey: ['locations'],
    queryFn: async () => {
      const res = await apiGet<Location[], PageMeta>('/locations', { per_page: 100 });
      return res.data;
    },
  });
}

export function useDeviceSensors(deviceId: number | undefined, includeHistory: boolean) {
  return useQuery({
    queryKey: ['device-sensors', deviceId, includeHistory],
    queryFn: async () => (await apiGet<Installation[]>(`/devices/${deviceId}/sensors`, { include_history: includeHistory })).data,
    enabled: deviceId !== undefined,
  });
}

export function useSensors(params: { sensor_type?: string; installed?: 'true' | 'false'; page?: number; per_page?: number }) {
  return useQuery({
    queryKey: ['sensors', params],
    queryFn: async () => {
      const res = await apiGet<SensorItem[], PageMeta>('/sensors', params as Record<string, string | number | undefined>);
      return { items: res.data, meta: res.meta! };
    },
  });
}

export function useSensorTypes() {
  return useQuery({
    queryKey: ['sensor-types'],
    queryFn: async () => (await apiGet<SensorType[], PageMeta>('/sensor-types', { per_page: 100 })).data,
  });
}

export function useSensorCalibrations(sensorId: number | undefined) {
  return useQuery({
    queryKey: ['sensor-calibrations', sensorId],
    queryFn: async () => (await apiGet<Calibration[], PageMeta>(`/sensors/${sensorId}/calibrations`, { per_page: 50 })).data,
    enabled: sensorId !== undefined,
  });
}
