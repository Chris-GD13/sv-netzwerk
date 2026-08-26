(() => {
  const apply = () => {
    // Die neue Portaloberfläche führt Schadenbericht und TF-/GF-Schadenbericht
    // bewusst als gegenseitig ausschließende Primärauswahl. Die alte
    // Kompatibilitätsergänzung darf dort keine zusätzlichen Checkboxen erzeugen.
    if (document.getElementById('vf-primary-outputs')) return;
    const outputs = document.getElementById('vf-outputs');
    if (outputs) {
      const firstExisting = outputs.querySelector('label');
      const ensure = (value, label, before = null) => {
        if (outputs.querySelector(`input[value="${value}"]`)) return;
        const item = document.createElement('label');
        item.innerHTML = `<input type="checkbox" value="${value}"> ${label}`;
        outputs.insertBefore(item, before || firstExisting || null);
      };
      const existingGeneral = outputs.querySelector('input[value="erstbericht"]');
      if (existingGeneral) {
        const label = existingGeneral.closest('label');
        if (label) label.lastChild.textContent = ' Allgemeiner Erstbericht';
      } else {
        ensure('erstbericht', 'Allgemeiner Erstbericht');
      }
      ensure('erstbericht_sv_gf', 'Erstbericht SV-GF (QS Engel)', outputs.querySelector('input[value="zwischenbericht"]')?.closest('label') || null);
    }

    const plaud = document.getElementById('vf-plaud-target');
    if (plaud) {
      const general = plaud.querySelector('option[value="erstbericht"]');
      if (general) general.textContent = 'Allgemeiner Erstbericht';
      if (!plaud.querySelector('option[value="erstbericht_sv_gf"]')) {
        const option = document.createElement('option');
        option.value = 'erstbericht_sv_gf';
        option.textContent = 'Erstbericht SV-GF (QS Engel)';
        general?.after(option);
      }
    }

    // Der obere Ausführen-Button arbeitet mit einer beim Seitenaufbau erzeugten alten Checkbox-Liste.
    // Für die nachgeladenen Erstbericht-Optionen den Klick deshalb vor dem alten Handler direkt an vf-start weiterreichen.
    const quickRun = document.getElementById('vf-instruction-run');
    if (quickRun && !quickRun.dataset.erstberichtBound) {
      quickRun.dataset.erstberichtBound = '1';
      quickRun.addEventListener('click', (event) => {
        const selectedNew = document.querySelector('#vf-outputs input[value="erstbericht"]:checked, #vf-outputs input[value="erstbericht_sv_gf"]:checked');
        if (!selectedNew) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        document.getElementById('vf-start')?.click();
      }, true);
    }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply, { once: true });
  else apply();
})();
