(() => {
  'use strict';
  const makeGroup = (id, title, description, options, host) => {
    if (!host || options.some(option => !option.panel) || document.getElementById(id)) return null;
    const group = document.createElement('details');
    group.id = id;
    group.className = 'vf-card vf-action-group';
    const summary = document.createElement('summary');
    const heading = document.createElement('strong'); heading.textContent = title;
    const hint = document.createElement('small'); hint.textContent = description;
    summary.append(heading, hint); group.append(summary);
    const label = document.createElement('label'); label.className = 'vf-action-choice'; label.append('Gewünschte Funktion');
    const select = document.createElement('select'); select.setAttribute('aria-label', title + ' – Funktion auswählen');
    options.forEach((option, index) => {
      const item = document.createElement('option'); item.value = String(index); item.textContent = option.label; select.append(item);
    });
    label.append(select); group.append(label);
    host.prepend(group);
    options.forEach(option => {
      const panel = option.panel;
      panel.classList.add('vf-grouped-panel');
      panel.classList.remove('vf-collapsible', 'is-collapsed');
      const header = panel.querySelector(':scope > .vf-step, :scope > summary');
      if (header) header.hidden = true;
      group.append(panel);
    });
    const update = () => options.forEach((option, index) => {
      const selected = String(index) === select.value;
      option.panel.hidden = !selected;
      if (option.panel.tagName === 'DETAILS') option.panel.open = group.open && selected;
    });
    select.addEventListener('change', update);
    group.addEventListener('toggle', update);
    update();
    return { reveal(index) { select.value = String(index); group.open = true; update(); group.scrollIntoView({ behavior: 'smooth', block: 'start' }); } };
  };
  const mailPanel = document.querySelector('.vf-mail');
  const mail = { reveal() {
    if (!mailPanel) return;
    mailPanel.classList.remove('is-collapsed');
    mailPanel.querySelector('.vf-step')?.setAttribute('aria-expanded', 'true');
    mailPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } };
  const mailArchive = document.querySelector('.vf-mail-history');
  const documents = document.querySelector('.vf-upload-card');
  if (mailArchive && documents) {
    documents.append(mailArchive);
    mailArchive.classList.add('vf-mail-under-documents');
    const heading = mailArchive.querySelector('.vf-step strong');
    const hint = mailArchive.querySelector('.vf-step small');
    if (heading) heading.textContent = 'Outlook-Mails hochladen / ablegen';
    if (hint) hint.textContent = 'MSG- und EML-Dateien zum aktiven Fall speichern und abgelegte Mails ansehen';
  }
  const offers = makeGroup('vf-offer-group', 'Angebot / KVA prüfen', 'Prüfung mit Entscheidung, Freigabe-E-Mail oder technischem Formular', [
    { label: 'Angebot prüfen und Entscheidung / E-Mail vorbereiten', panel: document.getElementById('dr-offer') },
    { label: 'Freigabe-E-Mail an Sanierer vorbereiten', panel: document.querySelector('.vf-kva') },
    { label: 'Technische Freigabe / Formular für VN', panel: document.querySelector('.vf-technical-release') },
  ], document.querySelector('.vf-release-grid'));
  const invoice = document.getElementById('dr-invoice');
  if (invoice) { const step = invoice.querySelector('summary b'); if (step) step.textContent = 'R'; }
  document.getElementById('vf-task-reply')?.addEventListener('click', () => mail?.reveal(0));
  document.getElementById('vf-task-kva')?.addEventListener('click', () => offers?.reveal(2));
  // Existing mail history reply actions fill this input and then focus it.
  document.getElementById('vf-mail-to')?.addEventListener('focus', () => mail?.reveal(0));
})();
