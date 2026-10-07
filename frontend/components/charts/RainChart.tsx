'use client';

import ReactECharts from 'echarts-for-react';
import * as React from 'react';
import { INTERVAL_SECONDS } from '@/lib/ranges';
import { insertGaps } from '@/lib/series';
import { formatWib, formatWibDate, formatWibTime } from '@/lib/time';
import type { ReadingsData } from '@/lib/api/types';

export function RainChart({ data, interval }: { data: ReadingsData; interval: '1h' | '1d' }) {
  const option = React.useMemo(() => {
    const rain = data.series.find((s) => s.sensor_type === 'rain_counter') ?? data.series[0];
    const values = rain ? rain.values : [];
    const { timestamps, values: gapped, gaps } = insertGaps(data.timestamps, values, INTERVAL_SECONDS[interval]);
    const points: Array<[number, number | null]> = timestamps.map((t, i) => [t, gapped[i]]);
    const markAreas = gaps.map((g) => [{ xAxis: g.start }, { xAxis: g.end }]);

    return {
      animation: false,
      grid: { left: 48, right: 24, top: 28, bottom: 32 },
      tooltip: {
        trigger: 'axis',
        formatter: (params: unknown) => {
          const arr = params as Array<{ marker: string; seriesName: string; value: [number, number | null] }>;
          if (!arr.length) return '';
          const t = arr[0].value[0];
          const time = interval === '1d' ? formatWibDate(t) : formatWib(t);
          const v = arr[0].value[1];
          const label = v === null ? 'Tidak ada data' : `${v.toLocaleString('id-ID')} mm`;
          return `${time}<br/>${arr[0].marker} ${arr[0].seriesName}: <strong>${label}</strong>`;
        },
      },
      xAxis: {
        type: 'time',
        axisLabel: {
          formatter: (v: number) => (interval === '1d' ? formatWibDate(v) : formatWibTime(v)),
        },
      },
      yAxis: {
        type: 'value',
        name: 'mm',
        min: 0,
      },
      series: [
        {
          name: 'Curah Hujan',
          type: 'bar',
          barMaxWidth: 24,
          itemStyle: { color: '#0E7490' },
          data: points,
          markArea:
            markAreas.length > 0
              ? { silent: true, itemStyle: { color: 'rgba(100,116,139,0.12)' }, data: markAreas }
              : undefined,
        },
      ],
    };
  }, [data, interval]);

  return <ReactECharts option={option} style={{ height: 260, width: '100%' }} notMerge lazyUpdate />;
}
