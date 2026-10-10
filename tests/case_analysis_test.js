const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '..', 'sv-netzwerk', 'public', 'intern', 'case-analysis.js'), 'utf8');
const importScript = fs.readFileSync(path.join(__dirname, '..', 'sv-netzwerk', 'public', 'intern', 'case-rules-import.js'), 'utf8');

async function setupRuleImport(page, options = {}) {
  const uploads = [];
  await page.route('http://portal.test/intern/versicherungswissen/', route => route.fulfill({ contentType: 'text/html', body: `<!doctype html>
    <section id="case-rules-import" hidden><p id="case-rules-status"></p><form id="case-rules-form">
    <select id="case-rules-target"><option value="standards">Standards</option></select>
    <input id="case-rules-files" type="file" multiple><input id="case-rules-replace" type="checkbox">
    <button type="submit">Importieren</button><button id="case-rules-refresh" type="button">Bestand</button></form>
    <ul id="case-rules-results"></ul><ul id="case-rules-list"></ul></section>` }));
  await page.route('**/intern/api/case-rules-import.php', async route => {
    if (options.denied) return route.fulfill({ status: 403, json: { ok: false, error: 'Nur Administratoren' } });
    if (route.request().method() === 'POST') {
      uploads.push(route.request().postData());
      if (options.failSecond && uploads.length === 2) return route.fulfill({ status: 422, json: { ok: false, error: 'Andere Fassung vorhanden' } });
      return route.fulfill({ json: { ok: true, file: { name: '<img src=x onerror=alert(1)>.md', duplicate: uploads.length > 1 } } });
    }
    return route.fulfill({ json: { ok: true, root: { name: '00_KI-Wissensbasis' }, files: [{ path: '<script>alert(1)</script>.md' }] } });
  });
  await page.goto('http://portal.test/intern/versicherungswissen/');
  await page.addScriptTag({ content: importScript });
  return uploads;
}

test('IONOS-Regelimport bestätigt Originale und rendert Namen nur als Text', async ({ page }) => {
  const uploads = await setupRuleImport(page);
  await expect(page.locator('#case-rules-import')).toBeVisible();
  await page.locator('#case-rules-files').setInputFiles([
    { name: 'MASTER-ARBEITSSTANDARD.md', mimeType: 'text/markdown', buffer: Buffer.from('# Master') },
    { name: 'MASTER-ARBEITSSTANDARD.md', mimeType: 'text/markdown', buffer: Buffer.from('# Master') },
  ]);
  await page.getByRole('button', { name: 'Importieren' }).click();
  await expect(page.locator('#case-rules-results')).toContainText('2 Originale bestätigt');
  expect(uploads).toHaveLength(2);
  expect(uploads[0]).toContain('# Master');
  expect(uploads[0]).toContain('standards');
  await expect(page.locator('#case-rules-results img')).toHaveCount(0);
  await expect(page.locator('#case-rules-list script')).toHaveCount(0);
});

test('IONOS-Regelimport meldet Teilimport ausdrücklich und stoppt weitere Dateien', async ({ page }) => {
  const uploads = await setupRuleImport(page, { failSecond: true });
  await page.locator('#case-rules-files').setInputFiles(['one.md', 'two.md', 'three.md'].map(name => ({
    name, mimeType: 'text/markdown', buffer: Buffer.from('# Regel'),
  })));
  await page.getByRole('button', { name: 'Importieren' }).click();
  await expect(page.locator('#case-rules-status')).toContainText('1 vorherige Originale sind bereits bestätigt');
  expect(uploads).toHaveLength(2);
  await expect(page.getByRole('button', { name: 'Importieren' })).toBeEnabled();
});

test('IONOS-Regelimport ist für Nichtadministratoren ausgeblendet', async ({ page }) => {
  await setupRuleImport(page, { denied: true });
  await expect(page.locator('#case-rules-import')).toBeHidden();
});

test('Echte Wissensseite bietet den IONOS-Administratorimport an', async ({ page }) => {
  await page.route('**/intern/api/**', async route => {
    if (route.request().url().includes('case-rules-import.php')) {
      return route.fulfill({ json: { ok: true, root: { name: '00_KI-Wissensbasis' }, files: [], message: 'Master importieren' } });
    }
    return route.fulfill({ json: { ok: true, user: { role: 'administrator', full_name: 'Test' },
      files: [], insurers: [], workbook: {}, items: [], results: [] } });
  });
  await page.goto((process.env.PORTAL_URL || 'http://127.0.0.1:4327') + '/intern/versicherungswissen/');
  await expect(page.locator('#case-rules-import')).toBeVisible();
  await expect(page.locator('#case-rules-status')).toContainText('Master importieren');
  await expect(page.locator('#case-rules-target option')).toHaveCount(4);
  await expect(page.locator('#case-rules-files')).toHaveAttribute('accept', '.md,.txt,.pdf,.docx,.xlsx,.pptx');
});
const result = {
  folder_id: 'case-one', task_id: 'task-one', summary: '<img src=x onerror=alert(1)>',
  facts: ['Angebot neu.pdf: Tür und Malerarbeiten'], assessment: 'Freigabeumfang prüfen.',
  open_points: ['Bodenaufnahme zuordnen'], next_steps: ['Nachweise anfordern'],
  rule_checks: ['MASTER.md: Keine ungeprüfte Freigabe'], reply_draft: 'Sehr geehrte Damen und Herren,\n\nbitte ergänzen Sie die Nachweise.',
  sources: [{ name: 'Aktuelle E-Mail/Angebot neu.pdf' }],
  rules: [{ path: '00_Standards_Regeln/MASTER.md', modified_at: '2026-10-10' }],
  task_results: [
    { request_id: 'request-1', request: 'Türposition im Angebot prüfen', answer: 'Die Türposition ist anhand des Originalangebots ausgearbeitet.', status: 'worked_out', source_refs: ['Aktuelle E-Mail/Angebot neu.pdf'] },
    { request_id: 'request-2', request: 'Bodenaufnahme zuordnen', answer: 'Für die Datumszuordnung fehlt das Aufnahmedatum.', status: 'open', source_refs: [] },
  ],
};

async function setup(page, options = {}) {
  const calls = [];
  let job = options.saved ? { id: 7, status: 'done', message: 'Gespeichert', result } : null;
  await page.route('**/intern/api/**', async route => {
    const body = route.request().postDataJSON(); calls.push(body);
    if (body.action === 'start') {
      if (options.failure) return route.fulfill({ status: 503, json: { ok: false, error: 'MD-Masterordner nicht erreichbar' } });
      job = { id: 7, status: 'done', message: 'Entwurf im Fall gespeichert', result };
      return route.fulfill({ json: { ok: true, job_id: 7 } });
    }
    return route.fulfill({ json: { ok: true, job } });
  });
  await page.route('http://portal.test/intern/versicherungsfaelle/**', route => route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><body>
    <div id="vf-active">Fall eins</div><section id="vf-task-context"><button id="vf-task-done">Erledigt</button></section>
    <button id="vf-analysis-start" disabled>Fall analysieren</button><button id="vf-analysis-refresh" disabled>Status</button>
    <textarea id="vf-analysis-order"></textarea><input type="file" multiple id="vf-analysis-files">
    <p id="vf-analysis-state"></p><div id="vf-analysis-result" hidden></div><textarea id="vf-mail-body"></textarea>
    <script>sessionStorage.setItem('svnet-case',JSON.stringify({folder_id:'case-one'}));</script></body></html>` }));
  await page.goto('http://portal.test/intern/versicherungsfaelle/?aufgabe=task-one');
  await page.addScriptTag({ content: script });
  await expect(page.locator('#vf-analysis-start')).toBeEnabled();
  return calls;
}

test('Analyse bleibt eingebunden, liest Aufgabenbezug und überträgt nur auf ausdrücklichen Klick', async ({ page }) => {
  const calls = await setup(page);
  await page.locator('#vf-analysis-order').fill('Tür und Boden getrennt prüfen');
  await page.locator('#vf-analysis-start').click();
  await expect(page.locator('#vf-analysis-result')).toBeVisible();
  expect(calls.find(call => call.action === 'start')).toMatchObject({ folder_id: 'case-one', task_id: 'task-one', instructions: 'Tür und Boden getrennt prüfen' });
  await expect(page.locator('#vf-analysis-result img')).toHaveCount(0);
  await expect(page.locator('#vf-analysis-result')).toContainText('Angebot neu.pdf');
  await expect(page.locator('#vf-analysis-result')).toContainText('00_Standards_Regeln/MASTER.md');
  await expect(page.locator('#vf-analysis-start')).toHaveText('Aufgabe ausarbeiten und beantworten');
  await expect(page.locator('#vf-analysis-result > section').first()).toContainText('Ausgearbeitet: Türposition im Angebot prüfen');
  await expect(page.locator('#vf-analysis-result')).toContainText('Noch offen: Bodenaufnahme zuordnen');
  await expect(page.locator('#vf-analysis-result details').first()).not.toHaveAttribute('open', '');
  await expect(page.locator('#vf-mail-body')).toHaveValue('');
  await page.getByRole('button', { name: 'Antwortentwurf ins E-Mail-Feld übernehmen' }).click();
  await expect(page.locator('#vf-mail-body')).toHaveValue(result.reply_draft);
  expect(calls.every(call => ['latest', 'start', 'status'].includes(call.action))).toBe(true);
  expect(page.context().pages()).toHaveLength(1);
});

test('Gespeicherte Analyse wird geladen, ohne erneut KI zu beauftragen', async ({ page }) => {
  const calls = await setup(page, { saved: true });
  await expect(page.locator('#vf-analysis-result')).toBeVisible();
  expect(calls.filter(call => call.action === 'start')).toHaveLength(0);
});

test('Nicht erreichbare MD-Quelle meldet Fehler statt fertiger Analyse', async ({ page }) => {
  await setup(page, { failure: true });
  await page.locator('#vf-analysis-start').click();
  await expect(page.locator('#vf-analysis-state')).toContainText('MD-Masterordner nicht erreichbar');
  await expect(page.locator('#vf-analysis-result')).toBeHidden();
  await expect(page.locator('#vf-analysis-start')).toBeEnabled();
});

test('Fallwechsel entfernt den vorherigen Entwurf und schützt das Mailfeld', async ({ page }) => {
  await setup(page, { saved: true });
  await expect(page.locator('#vf-analysis-result')).toBeVisible();
  await page.evaluate(() => {
    sessionStorage.removeItem('svnet-case');
    document.getElementById('vf-active').textContent = 'Kein Fall';
  });
  await expect(page.locator('#vf-analysis-result')).toBeHidden();
  await expect(page.locator('#vf-analysis-start')).toBeDisabled();
  await expect(page.locator('#vf-mail-body')).toHaveValue('');
});

test('Echte Fallseite startet die Analyse im Aufgabenbereich ohne neues Fenster', async ({ page }) => {
  await page.addInitScript(() => {
    sessionStorage.setItem('svnet-case', JSON.stringify({ folder_id: 'case-one', meta: { schaden_nr: '26-031578-4' } }));
  });
  await page.context().route('**/intern/api/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('case-analysis.php')) {
      const body = route.request().postDataJSON();
      return route.fulfill({ json: body.action === 'start' ? { ok: true, job_id: 8 }
        : { ok: true, job: body.action === 'status' ? { id: 8, status: 'done', message: 'Entwurf gespeichert', result } : null } });
    }
    if (url.pathname.endsWith('outlook-tasks.php')) return route.fulfill({ json: {
      ok: true, task: { id: 'task-one', subject: 'Tür und Boden', case_number: '26-031578-4', body: 'Bitte Angebot und Fotos prüfen.', attachments: [] },
    } });
    if (url.pathname.endsWith('google-drive-sync.php') && url.searchParams.get('action') === 'load_case') {
      return route.fulfill({ json: { ok: true, case: { id: 'case-one', meta: { schaden_nr: '26-031578-4' } } } });
    }
    return route.fulfill({ json: { ok: true, allowed: false, user: { role: 'sachverstaendiger', full_name: 'Test' }, results: [], items: [], files: [] } });
  });
  await page.goto((process.env.PORTAL_URL || 'http://127.0.0.1:4327') + '/intern/versicherungsfaelle/?schaden_nr=26-031578-4&aufgabe=task-one');
  await expect(page.locator('#vf-task-subject')).toHaveText('Tür und Boden');
  await expect(page.locator('#vf-analysis-start')).toBeEnabled();
  await page.locator('#vf-analysis-start').click();
  await expect(page.locator('#vf-analysis-result')).toBeVisible();
  expect(page.context().pages()).toHaveLength(1);
});
