export type LossRange = 'unter-25k' | '25k-100k' | '100k-500k' | 'ueber-500k';

export interface PracticeCaseFilter {
  lossType?: string;
  objectType?: string;
  lossRange?: LossRange;
  tag?: string;
}

export interface PracticeCaseMetric {
  label: string;
  value: string;
}

export const lossRangeLabels: Record<LossRange, string> = {
  'unter-25k': 'unter 25.000 EUR',
  '25k-100k': '25.000 bis 100.000 EUR',
  '100k-500k': '100.000 bis 500.000 EUR',
  'ueber-500k': 'über 500.000 EUR',
};
