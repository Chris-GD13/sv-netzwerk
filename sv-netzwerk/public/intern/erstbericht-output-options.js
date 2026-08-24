(() => {
  const apply = () => {
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
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply, { once: true });
  else apply();
})();
