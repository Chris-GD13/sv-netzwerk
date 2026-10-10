(() => {
  const setup = () => {
    const el = id => document.getElementById(id);
    const start = el('vf-analysis-start'), refresh = el('vf-analysis-refresh'), state = el('vf-analysis-state');
    const resultBox = el('vf-analysis-result'), order = el('vf-analysis-order'), files = el('vf-analysis-files');
    if (!start || !refresh || !state || !resultBox) return;
    const taskId = new URLSearchParams(location.search).get('aufgabe') || '';
    const startLabel = taskId ? 'Aufgabe ausarbeiten und beantworten' : 'Fall analysieren und ausarbeiten';
    if (el('vf-analysis-title')) el('vf-analysis-title').textContent = startLabel;
    let context = '', generation = 0, timer = null, running = false, starting = false;
    const active = () => {
      const raw = sessionStorage.getItem('svnet-case');
      if (!raw) return null;
      const value = JSON.parse(raw);
      return value?.folder_id ? value : null;
    };
    const request = async (input, url = '/intern/api/case-analysis.php', options = {}) => {
      const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 45000);
      try {
        const response = await fetch(url, {
          credentials: 'same-origin', method: 'POST', signal: controller.signal,
          headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input), ...options,
        });
        const data = await response.json();
        if (!response.ok || data.ok === false) throw Error(data.error || `HTTP ${response.status}`);
        return data;
      } catch (error) {
        if (error.name === 'AbortError') throw Error('Portalverbindung unterbrochen. Der Auftrag kann serverseitig weiterlaufen; bitte Status aktualisieren.');
        throw error;
      } finally { clearTimeout(timeout); }
    };
    const fail = error => {
      state.textContent = error.message;
      state.className = 'vf-meta vf-claims-bad';
    };
    const section = (title, content, parent = resultBox) => {
      const box = document.createElement('section'), heading = document.createElement('h3');
      heading.textContent = title; box.append(heading);
      if (Array.isArray(content)) {
        const list = document.createElement('ul');
        for (const text of content) { const item = document.createElement('li'); item.textContent = text; list.append(item); }
        box.append(list);
      } else { const text = document.createElement('p'); text.textContent = content; text.style.whiteSpace = 'pre-wrap'; box.append(text); }
      parent.append(box);
    };
    const render = (result, captured) => {
      resultBox.replaceChildren(); resultBox.hidden = false;
      if (Array.isArray(result.task_results) && result.task_results.length) {
        for (const item of result.task_results) {
          section((item.status === 'open' ? 'Noch offen: ' : 'Ausgearbeitet: ') + item.request, item.answer);
          if (item.source_refs?.length) section('Belege zum Anliegen', item.source_refs);
        }
      }
      section('Antwortentwurf – vor Versand prüfen', result.reply_draft);
      const transfer = document.createElement('button');
      transfer.type = 'button'; transfer.className = 'vf-secondary'; transfer.textContent = 'Antwortentwurf ins E-Mail-Feld übernehmen';
      transfer.onclick = () => {
        try {
          if (active()?.folder_id !== captured.folder_id) throw Error('Der aktive Fall hat gewechselt. Bitte die passende Analyse öffnen.');
          const target = el('vf-mail-body');
          if (!target) throw Error('Das E-Mail-Feld ist nicht verfügbar.');
          if (target.value.trim() && !window.confirm('Vorhandenen E-Mail-Entwurf durch den Analyseentwurf ersetzen?')) return;
          target.value = result.reply_draft; target.dispatchEvent(new Event('input', { bubbles: true }));
          target.scrollIntoView({ behavior: 'smooth', block: 'center' }); target.focus();
          state.textContent = 'Antwortentwurf übernommen. Empfänger, Inhalt und Anhänge vor dem manuellen Versand prüfen.';
        } catch (error) { fail(error); }
      };
      resultBox.append(transfer);
      section('Verbleibende offene Punkte', result.open_points);
      const analysis = document.createElement('details'), analysisSummary = document.createElement('summary');
      analysisSummary.textContent = 'Fachliche Ausarbeitung und Regelprüfung'; analysis.append(analysisSummary);
      section('Zusammenfassung', result.summary, analysis);
      section('Belegte Feststellungen / Quellen', result.facts, analysis);
      section('Fachliche Bewertung', result.assessment, analysis);
      section('Weitere Arbeitsschritte', result.next_steps, analysis);
      section('MD-Regelprüfung', result.rule_checks, analysis);
      resultBox.append(analysis);
      const provenance = document.createElement('details'), summary = document.createElement('summary');
      summary.textContent = 'Quellen und geladene MD-Vorgaben nachvollziehen'; provenance.append(summary);
      section('Gelesene Originalquellen', (result.sources || []).map(source => source.name + (source.duplicate_of ? ' (identisch mit '+source.duplicate_of+')' : '')), provenance);
      section('Vollständig geladene MD-Dateien', (result.rules || []).map(rule => rule.path + ' · ' + rule.modified_at), provenance);
      resultBox.append(provenance);
    };
    const load = async (captured, epoch, jobId) => {
      try {
        const data = await request({ action: jobId ? 'status' : 'latest', folder_id: captured.folder_id, task_id: taskId, job_id: jobId });
        if (epoch !== generation) return;
        const job = data.job;
        running = job?.status === 'running';
        start.disabled = running || starting; refresh.disabled = starting;
        start.textContent = running ? 'Ausarbeitung läuft …' : startLabel;
        state.className = 'vf-meta';
        state.textContent = job?.message || (taskId
          ? 'Startklar. Die Aufgabe oben wird mit allen Anhängen und Fallunterlagen ausgearbeitet; ein zusätzlicher Auftrag ist nicht erforderlich.'
          : 'Startklar. Die aktuellen Vorgaben werden vor jeder Analyse neu geladen.');
        if (job?.status === 'done') {
          if (!job.result || job.result.folder_id !== captured.folder_id || job.result.task_id !== taskId) throw Error('Analyse gehört zu einem anderen Fall oder einer anderen Aufgabe.');
          render(job.result, captured);
        } else if (job?.status === 'failed') state.className = 'vf-meta vf-claims-bad';
        if (running) timer = setTimeout(() => load(captured, epoch, job.id), 3000);
      } catch (error) {
        if (epoch !== generation) return;
        fail(error); refresh.disabled = false;
        if (running) timer = setTimeout(() => load(captured, epoch, jobId), 6000);
      }
    };
    const sync = () => {
      try {
        const current = active();
        const ready = current && (!taskId || (!el('vf-task-context')?.hidden && !el('vf-task-done')?.disabled));
        const key = ready ? current.folder_id + '|' + taskId : '';
        if (key === context) return;
        context = key; generation++; clearTimeout(timer); running = false; starting = false;
        resultBox.replaceChildren(); resultBox.hidden = true; order.value = ''; files.value = '';
        start.disabled = !ready; refresh.disabled = !ready; start.textContent = startLabel;
        state.textContent = ready ? 'Gespeicherte Analyse wird geprüft …' : 'Bitte zuerst den passenden Fall zur Aufgabe öffnen.';
        if (ready) load(current, generation);
      } catch (error) { start.disabled = true; refresh.disabled = true; fail(error); }
    };
    start.onclick = async () => {
      let epoch = generation;
      try {
        const current = active();
        if (!current || !context || running || starting) return;
        epoch = ++generation; clearTimeout(timer);
        starting = true; start.disabled = true;
        const selected = Array.from(files.files);
        if (selected.some(file => file.size > 30 * 1024 * 1024)) throw Error('Weitere Unterlagen dürfen höchstens 30 MB je Datei haben.');
        for (const file of selected) {
          state.textContent = 'Originalunterlage wird im aktiven Fall gespeichert: ' + file.name;
          const form = new FormData(); form.append('folder_id', current.folder_id); form.append('file', file);
          await request(null, '/intern/api/google-drive-sync.php?action=upload_case_document', { headers: {}, body: form });
          if (epoch !== generation) return;
        }
        files.value = ''; state.textContent = 'Analyse wird serverseitig gestartet …';
        const data = await request({ action: 'start', folder_id: current.folder_id, task_id: taskId, instructions: order.value.trim() });
        if (epoch !== generation) return;
        if (!data.job_id) throw Error('Kein Analyseauftrag angelegt.');
        running = true; resultBox.hidden = true; starting = false;
        load(current, epoch, data.job_id);
      } catch (error) {
        if (epoch !== generation) return;
        starting = false; start.disabled = false; fail(error);
      }
    };
    refresh.onclick = () => {
      clearTimeout(timer);
      try { const current = active(); if (current && context) load(current, generation); } catch (error) { fail(error); }
    };
    const observer = new MutationObserver(sync);
    for (const id of ['vf-active', 'vf-task-context']) {
      const target = el(id); if (target) observer.observe(target, { attributes: true, childList: true, subtree: true });
    }
    window.addEventListener('storage', sync);
    window.addEventListener('pagehide', () => { generation++; clearTimeout(timer); observer.disconnect(); });
    sync();
  };
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', setup, { once: true }) : setup();
})();
