export const FINANCIAL_REGISTER_INSTRUCTION = 'KVA-/Rechnungsübersicht: Sämtliche Originalunterlagen nach Inhalt auswerten, einschließlich Briefen, Sammelakten und Scan-Seiten. KVA und Rechnungen in getrennten Tabellen darstellen, jeweils mit Firma, Belegnummer, Leistung und Bruttobetrag. Bereits freigegebene KVA, tatsächlich bezahlte Rechnungen und noch erforderliche Entscheidungen getrennt kennzeichnen. Abgerechnete KVA zur Rechnung zuordnen und nicht doppelt summieren; Mehrkosten gesondert beurteilen. Belegte Vorzahlungen abziehen. Eine Gesamtberechnung nur bei vollständiger sachlicher und preislicher Anerkennung der offenen Belege ausweisen. Nachgewiesenen Trocknungsstrom mit belegten kWh und angegebenem Tarif separat berechnen. Tabellen und Erläuterungen knapp halten; anschließend nur zusätzliche konkrete Klärungspunkte einmal nennen. Keine allgemeinen Warntexte oder internen Quellen- und Regeldateilisten anhängen. Keine Beträge oder Zahlungen erfinden.';

export function setFinancialRegisterPreset(note, enabled) {
  const text = String(note ?? '');
  if (enabled) return text.includes(FINANCIAL_REGISTER_INSTRUCTION) ? text : [text.trim(), FINANCIAL_REGISTER_INSTRUCTION].filter(Boolean).join('\n\n');
  return text.replace(FINANCIAL_REGISTER_INSTRUCTION, '').replace(/\n{3,}/g, '\n\n').trim();
}

if (typeof document !== 'undefined') {
  const init = () => {
    const choice = document.getElementById('vf-financial-preset');
    const note = document.getElementById('vf-instructions');
    if (!choice || !note) return;
    const sync = () => { choice.checked = note.value.includes(FINANCIAL_REGISTER_INSTRUCTION); };
    choice.addEventListener('change', () => {
      note.value = setFinancialRegisterPreset(note.value, choice.checked);
      if (choice.checked) {
        const output = document.querySelector('#vf-outputs input[value="rechnungsregister"]');
        if (output) { output.checked = true; output.dispatchEvent(new Event('change', { bubbles: true })); }
      }
      note.dispatchEvent(new Event('input', { bubbles: true }));
    });
    note.addEventListener('input', sync);
    note.addEventListener('focus', sync);
    document.addEventListener('click', event => { if (!event.target.closest?.('.vf-financial-preset')) sync(); });
    window.addEventListener('focus', sync);
    sync();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
}
