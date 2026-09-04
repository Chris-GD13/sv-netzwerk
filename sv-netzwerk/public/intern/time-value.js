const finite = (value) => {
  const number = Number(value);
  return Number.isFinite(number) ? number : 0;
};

export const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, finite(value)));

export function calculateTimeValue(input = {}) {
  const newValueNet = Math.max(0, finite(input.newValueNet));
  const age = Math.max(0, finite(input.age));
  const lifetime = Math.max(0, finite(input.lifetime));
  const correctionFactor = clamp(input.correctionFactor || 1, 0.8, 1.2);
  const vatRate = Math.max(0, finite(input.vatRate));
  const limitedRemainingLife = Math.max(0, finite(input.limitedRemainingLife));
  const noLifeExtension = Boolean(input.noLifeExtension);
  const effectiveLifetime = limitedRemainingLife > 0
    ? Math.min(lifetime, age + limitedRemainingLife)
    : lifetime;

  if (newValueNet <= 0 || lifetime <= 0 || effectiveLifetime <= 0) {
    return { valid: false, error: 'Neuwert und technische Lebensdauer müssen größer als 0 sein.' };
  }

  const baseDepreciationRate = noLifeExtension ? 0 : clamp(age / effectiveLifetime, 0, 1);
  const depreciationRate = noLifeExtension ? 0 : clamp(baseDepreciationRate * correctionFactor, 0, 1);
  const timeValueRate = 1 - depreciationRate;
  const vatFactor = 1 + vatRate / 100;
  const newValueGross = newValueNet * vatFactor;
  const deductionNet = newValueNet * depreciationRate;
  const deductionGross = deductionNet * vatFactor;
  const timeValueNet = newValueNet * timeValueRate;
  const timeValueGross = timeValueNet * vatFactor;

  return {
    valid: true,
    newValueNet,
    newValueGross,
    age,
    lifetime,
    limitedRemainingLife,
    effectiveLifetime,
    correctionFactor,
    vatRate,
    noLifeExtension,
    baseDepreciationRate,
    depreciationRate,
    timeValueRate,
    deductionNet,
    deductionGross,
    timeValueNet,
    timeValueGross,
  };
}

export function buildTimeValueText(component, result, source = {}) {
  if (!result?.valid) return '';
  const money = (value) => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value);
  const percent = (value) => new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 }).format(value * 100) + ' %';
  const lifetimeNote = result.limitedRemainingLife > 0 && result.effectiveLifetime < result.lifetime
    ? ` Wegen der begrenzenden Restlebensdauer des verbundenen Bauteils von ${result.limitedRemainingLife} Jahren wurde im Sinne der Schicksalsgemeinschaft eine wirksame Gesamtnutzungsdauer von ${result.effectiveLifetime} Jahren zugrunde gelegt.`
    : '';
  const methodNote = result.noLifeExtension
    ? 'Da es sich um eine Teilreparatur ohne Verlängerung der Nutzungsdauer handelt, wurde kein Abzug „Neu für Alt“ vorgenommen.'
    : `Bei linearer Betrachtung ergibt sich aus einem Alter von ${result.age} Jahren und einer zugrunde gelegten technischen Lebensdauer von ${result.effectiveLifetime} Jahren zunächst eine Entwertung von ${percent(result.baseDepreciationRate)}. Unter Berücksichtigung des Korrekturfaktors ${new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 }).format(result.correctionFactor)} beträgt die Entwertung ${percent(result.depreciationRate)} und der verbleibende Zeitwertanteil ${percent(result.timeValueRate)}.`;
  const sourceTitle = source.title || 'BTE-Arbeitsblatt „Lebensdauer von Bauteilen, Zeitwerte“';
  const sourceStand = source.stand || '14.03.2008';
  return `Für das Bauteil „${component}“ wurde die technische Lebensdauer anhand der Quelle ${sourceTitle} (${sourceStand}${source.page ? `, Tabelle Seite ${source.page}` : ''}) mit ${result.lifetime} Jahren angesetzt.${source.usageNote || ''}${lifetimeNote} ${methodNote} Ausgehend von einem Neuwert von ${money(result.newValueNet)} netto (${money(result.newValueGross)} brutto) ergibt sich ein Abzug „Neu für Alt“ von ${money(result.deductionNet)} netto (${money(result.deductionGross)} brutto). Der technische Zeitwert beträgt damit ${money(result.timeValueNet)} netto bzw. ${money(result.timeValueGross)} brutto. Die Tabellenwerte sind Richtwerte; Beanspruchung, Qualität, Pflege, Unterhaltung, Nutzbarkeit und der konkrete Einzelfall wurden sachverständig gewürdigt.`;
}
