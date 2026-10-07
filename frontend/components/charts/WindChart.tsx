'use client';

import ReactECharts from 'echarts-for-react';
import * as React from 'react';
import { insertGaps } from '@/lib/series';
import { formatWib, formatWibTime } from '@/lib/time';
import type { ReadingsData } from '@/lib/api/types';

/**
 * Fallback wind rose per FRONTEND.md §5.2: endpoint /readings/wind-rose belum
 * tersedia di backend (ditandai bonus), jadi dipakai line kecepatan + scatter
 * arah pada sumbu kanan (0-360°) sebagai representasi minimal yang tetap jujur
 * soal gap data.
 */
export function WindChart({ data, intervalSeconds }: { data: ReadingsData; intervalSeconds: number }) {
  const option = React.useMemo(() => {
    const speed = data.series.find((s) => s.sensor_type === 'wind_speed');
    const dir = data.series.find((s) => s.sensor_type === 'wind_dir');

    const speedGapped = speed ? insertGaps(data.timestamps, speed.values, intervalSeconds) : null;

    const dirPoints: Array<[number, number | null]> = dir ? data.timestamps.map((t, i) => [t, dir.values[i]]) : [];

    return {
      animation: false,
      grid: { left: 48, right: 48, top: 28, bottom: 32 },
      tooltip: {
        trigger: 'axis',
        formatter: (params: unknown) => {
          const arr = params as Array<{ marker: string; seriesName: string; value: [number, number | null] }>;
          if (!arr.length) return '';
          const time = formatWib(arr[0].value[0]);
          const lines = arr.map((p) => `${p.marker} ${p.seriesName}: ${p.value[1] === null ? 'Tidak ada data' : p.value[1]}`);
          return [time, ...lines].join('<br/>');
        },
      },
      legend: { top: 0, textStyle: { fontSize: 11 } },
      xAxis: { type: 'time', axisLabel: { formatter: (v: number) => formatWibTime(v) } },
      yAxis: [
        { type: 'value', name: 'm/s', min: 0 },
        { type: 'value', name: '°', min: 0, max: 360, interval: 90, position: 'right' },
      ],
      series: [
        speedGapped
          ? {
              name: 'Kecepatan Angin',
              type: 'line',
              yAxisIndex: 0,
              showSymbol: false,
              connectNulls: false,
              sampling: 'lttb',
              lineStyle: { width: 2, color: '#0E7490' },
              data: speedGapped.timestamps.map((t, i) => [t, speedGapped.values[i]]),
            }
          : null,
        dir
          ? {
              name: 'Arah Angin',
              type: 'scatter',
              yAxisIndex: 1,
              symbolSize: 4,
              itemStyle: { color: '#CB9D51' },
              data: dirPoints,
            }
          : null,
      ].filter(Boolean),
    };
  }, [data, intervalSeconds]);

  return <ReactECharts option={option} style={{ height: 260, width: '100%' }} notMerge lazyUpdate />;
}
