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
    const badge = document.createElement('b'); badge.className = 'vf-step-number'; badge.textContent = '6';
    const text = document.createElement('div'); text.append(heading, hint); summary.append(badge, text); group.append(summary);
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
    return { group, select, reveal(index) { select.value = String(index); select.dispatchEvent(new Event('change')); group.open = true; update(); group.scrollIntoView({ behavior: 'smooth', block: 'start' }); } };
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
  const review = makeGroup('vf-review-group', 'KVA / Rechnung prüfen', 'Belegart auswählen, prüfen und Entscheidung mit Mail vorbereiten', [
    { label: 'KVA / Angebot prüfen', panel: document.getElementById('dr-offer') },
    { label: 'Rechnung prüfen', panel: document.getElementById('dr-invoice') },
    { label: 'Freigabe-E-Mail an Sanierer vorbereiten', panel: document.querySelector('.vf-kva') },
    { label: 'Technische Freigabe / Formular für VN', panel: document.querySelector('.vf-technical-release') },
  ], document.querySelector('.vf-release-grid'));
  if (review) {
    const choice = review.select.closest('label'); choice.hidden = true;
    const types = document.createElement('fieldset'); types.className = 'vf-review-types';
    types.innerHTML = '<legend>Was soll geprüft werden?</legend><label><input type="radio" name="vf-review-kind" value="offer" checked> KVA / Angebot</label><label><input type="radio" name="vf-review-kind" value="invoice"> Rechnung</label>';
    const output = document.createElement('label'); output.className = 'vf-action-choice'; output.append('Gewünschte Ausgabe');
    const mode = document.createElement('select'); mode.setAttribute('aria-label', 'Ausgabe der KVA-Prüfung');
    for (const [value, title] of [['0', 'Prüfung und Entscheidung / E-Mail'], ['2', 'Freigabe-E-Mail an Sanierer'], ['3', 'Technische Freigabe / Formular für VN']]) {
      const option = document.createElement('option'); option.value = value; option.textContent = title; mode.append(option);
    }
    output.append(mode); choice.after(types, output);
    const sync = () => {
      const invoice = review.select.value === '1';
      types.querySelector('input[value="invoice"]').checked = invoice;
      types.querySelector('input[value="offer"]').checked = !invoice;
      output.hidden = invoice;
      if (!invoice) mode.value = review.select.value;
    };
    types.addEventListener('change', () => {
      review.select.value = types.querySelector('input[value="invoice"]').checked ? '1' : mode.value;
      review.select.dispatchEvent(new Event('change'));
    });
    mode.addEventListener('change', () => { review.select.value = mode.value; review.select.dispatchEvent(new Event('change')); });
    review.select.addEventListener('change', sync); sync();
    const oldReviews = document.getElementById('vf-document-reviews');
    if (oldReviews && !oldReviews.children.length) oldReviews.remove();
  }
  const numberedSummary = (selector, number) => {
    const summary = document.querySelector(selector); if (!summary) return;
    const title = document.createElement('strong'); title.textContent = summary.textContent;
    const badge = document.createElement('b'); badge.className = 'vf-step-number'; badge.textContent = String(number);
    summary.replaceChildren(badge, title); summary.classList.add('vf-numbered-summary');
  };
  numberedSummary('.vf-processing > summary', 7);
  numberedSummary('#vf-system-panel > summary', 10);
  for (const [selector, number] of [['.vf-plaud .vf-plaud-head > span', 8], ['.vf-polycam .vf-plaud-head > span', 9]]) {
    const badge = document.querySelector(selector); if (badge) { badge.textContent = String(number); badge.classList.add('vf-step-number'); }
  }
  document.getElementById('vf-task-reply')?.addEventListener('click', () => mail?.reveal(0));
  document.getElementById('vf-task-kva')?.addEventListener('click', () => review?.reveal(3));
  // Existing mail history reply actions fill this input and then focus it.
  document.getElementById('vf-mail-to')?.addEventListener('focus', () => mail?.reveal(0));
})();
