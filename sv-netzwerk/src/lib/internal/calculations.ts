import type { CalculationParameterMap } from './types';

const defaultParameters: CalculationParameterMap = {
  glassDensityKgPerM2Mm: 2.5,
  frameWeightFactor: 0.18,
  safetyFactor: 1.1,
};

function toNumber(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  if (typeof value === 'string') {
    const normalized = value.replace(',', '.').trim();
    if (!normalized) return null;
    const parsed = Number(normalized);
    return Number.isFinite(parsed) ? parsed : null;
  }
  return null;
}

export function normalizeCalculationParameters(input: Partial<CalculationParameterMap> | null | undefined): CalculationParameterMap {
  return {
    glassDensityKgPerM2Mm: toNumber(input?.glassDensityKgPerM2Mm) ?? defaultParameters.glassDensityKgPerM2Mm,
    frameWeightFactor: toNumber(input?.frameWeightFactor) ?? defaultParameters.frameWeightFactor,
    safetyFactor: toNumber(input?.safetyFactor) ?? defaultParameters.safetyFactor,
  };
}

export function calculateWindowWeights(
  data: Record<string, unknown>,
  parameters: Partial<CalculationParameterMap> | null | undefined,
) {
  const params = normalizeCalculationParameters(parameters);
  const widthMm = toNumber(data.glazing_width_mm);
  const heightMm = toNumber(data.glazing_height_mm);
  const thicknessMm = toNumber(data.glass_thickness_mm) ?? estimateThicknessFromStructure(String(data.glass_structure ?? ''));
  const frameWeight = toNumber(data.estimated_frame_weight_kg) ?? 0;
  const safetyMargin = toNumber(data.safety_margin_kg) ?? 0;

  const glassAreaM2 = widthMm && heightMm ? (widthMm / 1000) * (heightMm / 1000) : 0;
  const glassWeight = glassAreaM2 && thicknessMm
    ? round(glassAreaM2 * thicknessMm * params.glassDensityKgPerM2Mm)
    : 0;
  const autoFrameWeight = frameWeight || round(glassWeight * params.frameWeightFactor);
  const totalWingWeight = round(glassWeight + autoFrameWeight);
  const appliedWeight = round((toNumber(data.weight_from_manufacturer_kg)
    ?? toNumber(data.weight_from_inventory_kg)
    ?? totalWingWeight) * params.safetyFactor + safetyMargin);

  return {
    glassWeightKg: glassWeight,
    frameWeightKg: round(autoFrameWeight),
    totalWingWeightKg: totalWingWeight,
    appliedTestWeightKg: appliedWeight,
  };
}

function estimateThicknessFromStructure(structure: string): number | null {
  const matches = structure.match(/\d+(?:[.,]\d+)?/g);
  if (!matches?.length) return null;
  return round(matches
    .map((match) => Number(match.replace(',', '.')))
    .filter((value) => Number.isFinite(value) && value <= 25)
    .reduce((sum, value) => sum + value, 0));
}

function round(value: number) {
  return Math.round(value * 10) / 10;
}
