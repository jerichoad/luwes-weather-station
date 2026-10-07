'use client';

import ReactECharts from 'echarts-for-react';
import * as React from 'react';
import { formatWibTime } from '@/lib/time';

export function BatteryTrendChart({ timestamps, values }: { timestamps: number[]; values: (number | null)[] }) {
  const option = React.useMemo(() => {
    return {
      animation: false,
      grid: { left: 40, right: 16, top: 20, bottom: 28 },
      tooltip: {
        trigger: 'axis',
        formatter: (params: unknown) => {
          const arr = params as Array<{ marker: string; seriesName: string; value: [number, number | null] }>;
          if (!arr.length) return '';
          const t = formatWibTime(arr[0].value[0]);
          const v = arr[0].value[1];
          return `${t} WIB<br/>${arr[0].marker} Baterai: <strong>${v !== null ? `${v} V` : '—'}</strong>`;
        },
      },
      xAxis: { type: 'time', axisLabel: { formatter: (v: number) => formatWibTime(v) } },
      yAxis: { type: 'value', min: 3.0, max: 4.4, name: 'V' },
      series: [
        {
          name: 'Tegangan',
          type: 'line',
          showSymbol: false,
          sampling: 'lttb',
          lineStyle: { width: 2, color: '#313D6F' },
          areaStyle: { color: 'rgba(49,61,111,0.08)' },
          data: timestamps.map((t, i) => [t, values[i]]),
        },
      ],
    };
  }, [timestamps, values]);

  return <ReactECharts option={option} style={{ height: 180, width: '100%' }} notMerge lazyUpdate />;
}
