'use client';

import ReactECharts from 'echarts-for-react';
import type { SeriesOption } from 'echarts';
import * as React from 'react';
import { insertGaps } from '@/lib/series';
import { formatWib, formatWibTime } from '@/lib/time';
import type { ReadingsData } from '@/lib/api/types';

export function TempHumidityChart({ data, intervalSeconds }: { data: ReadingsData; intervalSeconds: number }) {
  const option = React.useMemo(() => {
    const temp = data.series.find((s) => s.sensor_type === 'temp_air');
    const hum = data.series.find((s) => s.sensor_type === 'humidity');

    const tempGapped = temp ? insertGaps(data.timestamps, temp.values, intervalSeconds) : null;
    const humGapped = hum ? insertGaps(data.timestamps, hum.values, intervalSeconds) : null;

    const series: SeriesOption[] = [];
    const markAreas: Array<[{ xAxis: number }, { xAxis: number }]> = [];

    if (tempGapped) {
      for (const g of tempGapped.gaps) markAreas.push([{ xAxis: g.start }, { xAxis: g.end }]);
      series.push({
        name: `${temp!.label ?? 'Suhu'} (°C)`,
        type: 'line',
        yAxisIndex: 0,
        showSymbol: false,
        connectNulls: false,
        sampling: 'lttb',
        itemStyle: { color: '#CB9D51' },
        lineStyle: { width: 2 },
        data: tempGapped.timestamps.map((t, i) => [t, tempGapped.values[i]]),
        markArea:
          markAreas.length > 0
            ? { itemStyle: { color: 'rgba(100,116,139,0.12)' }, data: markAreas, label: { show: false } }
            : undefined,
      });
    }

    if (humGapped) {
      series.push({
        name: `${hum!.label ?? 'Kelembapan'} (%)`,
        type: 'line',
        yAxisIndex: 1,
        showSymbol: false,
        connectNulls: false,
        sampling: 'lttb',
        itemStyle: { color: '#313D6F' },
        lineStyle: { width: 2 },
        data: humGapped.timestamps.map((t, i) => [t, humGapped.values[i]]),
      });
    }

    return {
      animation: false,
      grid: { left: 48, right: 48, top: 36, bottom: 32 },
      tooltip: {
        trigger: 'axis',
        valueFormatter: (v: unknown) => (v === null || v === undefined ? 'Tidak ada data' : String(v)),
        formatter: (params: unknown) => {
          const arr = params as Array<{ axisValue: number; marker: string; seriesName: string; value: [number, number | null] }>;
          if (!arr.length) return '';
          const time = formatWib(arr[0].value[0]);
          const lines = arr.map((p) => `${p.marker} ${p.seriesName}: ${p.value[1] === null ? 'Tidak ada data' : p.value[1]}`);
          return [time, ...lines].join('<br/>');
        },
      },
      legend: { top: 0, textStyle: { fontSize: 11 } },
      xAxis: {
        type: 'time',
        axisLabel: { formatter: (v: number) => formatWibTime(v) },
      },
      yAxis: [
        { type: 'value', name: '°C', position: 'left', axisLabel: { formatter: '{value}°' } },
        { type: 'value', name: '%', position: 'right', min: 0, max: 100 },
      ],
      series,
    };
  }, [data, intervalSeconds]);

  return <ReactECharts option={option} style={{ height: 300, width: '100%' }} notMerge lazyUpdate />;
}
