import { SegmentedControl } from '@/components/ui';
import type { PresetRange } from '@/lib/ranges';

const options: Array<{ value: PresetRange; label: string }> = [
  { value: '24h', label: '24 Jam' },
  { value: '7d', label: '7 Hari' },
  { value: '30d', label: '30 Hari' },
];

export function RangePicker({ value, onChange }: { value: PresetRange; onChange: (v: PresetRange) => void }) {
  return <SegmentedControl value={value} onChange={onChange} options={options} label="Pilih rentang waktu" />;
}
