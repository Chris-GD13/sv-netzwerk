const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const BASE = process.env.PORTAL_URL || 'http://127.0.0.1:4327';
const tasks = [
  { id: 'oldest', received_at: '2026-09-21T08:15:00Z', case_number: '26-053198-8', from: 'Die Regulierer', subject: 'Unterlagen zur Bearbeitung ' + 'zum Schaden '.repeat(12), preview: 'Freundliche Gruesse und lange Signatur', edit_url: '/intern/versicherungsfaelle/?schaden_nr=26-053198-8&aufgabe=oldest' },
  { id: 'missing', received_at: '2026-09-22T08:15:00Z', case_number: '', from: 'Zentrale', subject: 'Rueckfrage ohne Fallnummer', preview: 'Weitere Unterlagen fehlen', edit_url: '/intern/versicherungsfaelle/?aufgabe=missing' },
  { id: 'newest', received_at: '2026-09-23T08:15:00Z', case_number: '61-1181377', from: 'Anna', subject: 'Rechnung Schornsteinfeger', preview: 'Rechnung zur Pruefung', edit_url: '/intern/versicherungsfaelle/?schaden_nr=61-1181377&aufgabe=newest' },
  { id: 'unsafe', received_at: '2026-09-22T09:15:00Z', case_number: '', from: '<img src=x onerror=alert(1)>', subject: '<script>alert(1)</script>', preview: '<b>Keine HTML-Ausfuehrung</b>', edit_url: '/intern/versicherungsfaelle/?aufgabe=unsafe' },
];

async function openTasks(page, options = {}) {
  let items = (options.items || tasks).map(item => ({ ...item }));
  const moves = [];
  await page.context().route('**/intern/api/**', async route => {
    const url = new URL(route.request().url());
    const action = url.searchParams.get('action');
    const id = url.searchParams.get('id');
    if (url.pathname.endsWith('/outlook-tasks.php')) {
      if (action === 'list') {
        if (options.listError) return route.fulfill({ status: 503, json: { ok: false, error: 'Outlook nicht erreichbar' } });
        return route.fulfill({ json: { ok: true, items, mailbox: 'test@example.invalid', folder: 'Zu erledigen' } });
      }
      if (action === 'detail') {
        const item = items.find(item => item.id === id);
        return route.fulfill({ json: { ok: true, task: { ...item, body: 'Vollstaendige Nachricht: ' + item.preview, attachments: [] } } });
      }
      if (action === 'eml') return route.fulfill({ contentType: 'message/rfc822', body: 'Subject: Test\r\n\r\nTest mail' });
      if (action === 'move') {
        const body = route.request().postDataJSON();
        moves.push(body.id);
        if (options.moveGate) await options.moveGate;
        if (options.moveError) return route.fulfill({ status: 500, json: { ok: false, error: 'Verschieben fehlgeschlagen' } });
        items = items.filter(item => item.id !== body.id);
        return route.fulfill({ json: { ok: true, id: body.id } });
      }
    }
    if (url.pathname.endsWith('/google-drive-sync.php')) {
      if (action === 'search_cases') return route.fulfill({ json: { ok: true, results: [{ id: 'test-folder', name: options.storedObject || 'Testfall', meta: { schaden_nr: options.storedNumber || url.searchParams.get('q'), vn_objekt: options.storedObject || 'Testfall' } }] } });
      if (action === 'load_case') return route.fulfill({ json: { ok: true, case: { id: 'test-folder', meta: { schaden_nr: options.storedNumber || tasks[0].case_number, vn_objekt: options.storedObject || 'Testfall' } } } });
      if (action === 'upload_case_document') {
        if (options.archiveError) return route.fulfill({ status: 500, json: { ok: false, error: 'IONOS nicht erreichbar' } });
        return route.fulfill({ json: { ok: true } });
      }
    }
    return route.fulfill({ json: { ok: true, allowed: false, user: { role: 'sachverstaendiger', full_name: 'Test' } } });
  });
  await page.goto(`${BASE}/intern/aufgaben/`);
  if (!options.listError) {
    await expect(page.locator('#tasks-count')).toHaveText(`${items.length} offene Aufgaben`);
    await expect(page.locator('#tasks-sync-status')).toContainText('neu in der IONOS-Fallakte');
  }
  return moves;
}

const ids = page => page.locator('#tasks-list .task-row').evaluateAll(rows => rows.map(row => row.dataset.id));

test('kompakte Tabelle, sichtbare Stile, Vorschau und sichere Textausgabe', async ({ page }) => {
  await openTasks(page);
  await expect(page.locator('.tasks-table thead th')).toHaveCount(6);
  expect(await ids(page)).toEqual(['oldest', 'missing', 'unsafe', 'newest']);
  const row = page.locator('[data-id="oldest"]');
  expect(await row.evaluate(el => el.getBoundingClientRect().height)).toBeLessThan(160);
  expect(await row.locator('.task-subject strong').evaluate(el => parseFloat(getComputedStyle(el).fontSize))).toBeLessThan(18);
  expect(await row.locator('button').evaluate(el => getComputedStyle(el).backgroundColor)).toBe('rgb(52, 131, 75)');
  await expect(row.locator('.task-preview')).not.toBeVisible();
  await row.locator('summary').click();
  await expect(row.locator('.task-preview')).toBeVisible();
  await expect(row.locator('.task-actions a')).toHaveAttribute('href', tasks[0].edit_url);
  await expect(row.locator('.task-case a')).toHaveAttribute('href', tasks[0].edit_url);
  await expect(row.locator('.task-case a')).toHaveAttribute('target', '_blank');
  await expect(row.locator('.task-case a')).toHaveAccessibleName(/mit Aufgabe:.*neuer Tab/);
  await expect(page.locator('[data-id="missing"] .task-case a')).toHaveCount(0);
  await expect(page.locator('#tasks-list img, #tasks-list script')).toHaveCount(0);
  await expect(page.locator('[data-id="unsafe"] .task-subject strong')).toHaveText(tasks[3].subject);
  await expect(row.locator('.task-archive')).toHaveText(/Mail \+ Anhänge abgelegt|Bereits abgelegt/);
  await expect(page.locator('[data-id="missing"] .task-archive')).toHaveText('Schadennummer fehlt');
});

test('Falllink behaelt Aufgabe im Fall und gefilterte Arbeitsliste im Ursprungstab', async ({ page }) => {
  await openTasks(page);
  await page.locator('#tasks-search').fill('26-053198');
  const popupPromise = page.waitForEvent('popup');
  await page.locator('[data-id="oldest"] .task-case a').click();
  const popup = await popupPromise;
  await expect(popup.locator('#vf-task-context')).toBeVisible();
  await expect(popup.locator('#vf-task-subject')).toHaveText(tasks[0].subject);
  await expect(popup.locator('#vf-task-body')).toHaveText('Vollstaendige Nachricht: ' + tasks[0].preview);
  await expect(popup).toHaveURL(/aufgabe=oldest/);
  await expect(page).toHaveURL(/\/intern\/aufgaben\/$/);
  await expect(page.locator('#tasks-search')).toHaveValue('26-053198');
  expect(await ids(page)).toEqual(['oldest']);
  await popup.close();
});

test('Suche, Fallfilter, Sortierung und echte Leerzustaende', async ({ page }) => {
  await openTasks(page);
  await page.locator('#tasks-sort').selectOption('newest');
  expect(await ids(page)).toEqual(['newest', 'unsafe', 'missing', 'oldest']);
  await page.locator('#tasks-sort').selectOption('sender');
  expect((await ids(page)).indexOf('newest')).toBeLessThan((await ids(page)).indexOf('oldest'));
  await page.locator('#tasks-sort').selectOption('case');
  expect((await ids(page)).slice(0, 2)).toEqual(['oldest', 'newest']);
  await page.locator('#tasks-search').fill('SCHORNSTEINFEGER');
  await expect(page.locator('#tasks-list .task-row')).toHaveCount(1);
  await expect(page.locator('#tasks-count')).toHaveText('4 offene Aufgaben');
  await expect(page.locator('#tasks-status')).toContainText('1 von 4');
  await page.locator('#tasks-search').fill('26-053198');
  expect(await ids(page)).toEqual(['oldest']);
  await page.locator('#tasks-search').fill('Anna');
  expect(await ids(page)).toEqual(['newest']);
  await page.locator('#tasks-search').fill('Keine Treffer');
  await expect(page.locator('#tasks-empty-title')).toHaveText('Keine passenden Aufgaben');
  await expect(page.locator('#tasks-table-wrap')).not.toBeVisible();
  await page.locator('#tasks-reset').click();
  await expect(page.locator('#tasks-empty')).not.toBeVisible();
  await page.locator('#tasks-case-filter').selectOption('unresolved');
  expect(await ids(page)).toEqual(['missing', 'unsafe']);
  await page.locator('#tasks-case-filter').selectOption('identified');
  expect(await ids(page)).toEqual(['oldest', 'newest']);
});

test('Erledigen aktualisiert alle Aufgaben, nicht nur gefilterte Zeilen', async ({ page }) => {
  const moves = await openTasks(page);
  await page.locator('#tasks-search').fill('Schornsteinfeger');
  await page.locator('[data-done="newest"]').click();
  await expect(page.locator('#tasks-count')).toHaveText('3 offene Aufgaben');
  await expect(page.locator('#tasks-empty-title')).toHaveText('Keine passenden Aufgaben');
  await page.locator('#tasks-reset').click();
  expect(await ids(page)).not.toContain('newest');
  await page.locator('#tasks-drive-sync').click();
  await expect(page.locator('#tasks-sync-status')).toContainText('0 E-Mail(s) neu');
  expect(moves).toEqual(['newest']);
  await page.locator('#tasks-refresh').click();
  await expect(page.locator('#tasks-count')).toHaveText('3 offene Aufgaben');
});

test('Verschiebefehler bleibt sichtbar und entfernt keine Aufgabe', async ({ page }) => {
  await openTasks(page, { moveError: true });
  await page.locator('[data-done="oldest"]').click();
  await expect(page.locator('#tasks-status')).toHaveText('Verschieben fehlgeschlagen');
  await expect(page.locator('[data-done="oldest"]')).toBeEnabled();
  await expect(page.locator('#tasks-list .task-row')).toHaveCount(4);
});

test('laufendes Erledigen bleibt auch nach Filterwechsel gesperrt', async ({ page }) => {
  let release;
  const moveGate = new Promise(resolve => { release = resolve; });
  const moves = await openTasks(page, { moveGate });
  await page.locator('[data-done="oldest"]').click();
  await expect(page.locator('[data-done="oldest"]')).toBeDisabled();
  await page.locator('#tasks-case-filter').selectOption('identified');
  await expect(page.locator('[data-done="oldest"]')).toBeDisabled();
  release();
  await expect(page.locator('[data-id="oldest"]')).toHaveCount(0);
  expect(moves).toEqual(['oldest']);
});

test('IONOS-Fehler pro Zeile verdeckt nicht die Aufgabenanzeige', async ({ page }) => {
  await openTasks(page, { archiveError: true });
  await expect(page.locator('[data-id="oldest"] .task-archive')).toHaveText('IONOS nicht erreichbar');
  await expect(page.locator('#tasks-sync-status')).toContainText('Übertragungsfehler');
  await expect(page.locator('#tasks-status')).toHaveText('4 von 4 Aufgaben angezeigt');
  await expect(page.locator('[data-id="oldest"] .task-archive')).toHaveClass(/is-error/);
  expect(await page.locator('[data-id="oldest"] .task-archive').evaluate(el => getComputedStyle(el).color)).toBe('rgb(166, 61, 30)');
});

test('leerer Outlook-Ordner und Ladefehler werden nicht verwechselt', async ({ page }) => {
  await openTasks(page, { items: [] });
  await expect(page.locator('#tasks-empty')).toBeVisible();
  await expect(page.locator('#tasks-empty-title')).toHaveText('Keine offenen Aufgaben');
  await page.context().unroute('**/intern/api/**');
  await openTasks(page, { listError: true });
  await expect(page.locator('#tasks-status')).toHaveText('Outlook nicht erreichbar');
  await expect(page.locator('#tasks-empty')).not.toBeVisible();
  await page.locator('#tasks-search').fill('Test');
  await expect(page.locator('#tasks-status')).toHaveText('Outlook nicht erreichbar');
});

test('mobile Tabelle scrollt lokal statt die ganze Seite zu verbreitern', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await openTasks(page);
  const sizes = await page.locator('#tasks-table-wrap').evaluate(el => ({
    visible: el.clientWidth,
    content: el.scrollWidth,
    page: document.documentElement.scrollWidth,
    viewport: window.innerWidth,
  }));
  expect(sizes.content).toBeGreaterThan(sizes.visible);
  expect(sizes.page).toBeLessThanOrEqual(sizes.viewport);
  await expect(page.locator('#tasks-search')).toBeVisible();
});

test('20 Aufgaben bleiben bei langen Betreffzeilen kompakt', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 1920, height: 1080 });
  const items = Array.from({ length: 20 }, (_, index) => ({
    ...tasks[index % 3],
    id: `task-${index}`,
    edit_url: `/intern/versicherungsfaelle/?aufgabe=task-${index}`,
  }));
  await openTasks(page, { items });
  await expect(page.locator('#tasks-list .task-row')).toHaveCount(20);
  const heights = await page.locator('#tasks-list .task-row').evaluateAll(rows => rows.map(row => row.getBoundingClientRect().height));
  expect(Math.max(...heights)).toBeLessThan(120);
  expect(await page.locator('#tasks-table-wrap').evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('aufgaben-desktop.png'), fullPage: true });
});

test('geteilte Schadennummer verweist auf denselben bestehenden Fall mit Aufgabe', async ({ page }) => {
  const item = {
    ...tasks[0],
    case_number: '26-165840-0',
    subject: 'Fwd: Schaden 26-165 840-0 / HC CAS-69406-F8F9P2',
    edit_url: '/intern/versicherungsfaelle/?schaden_nr=26-165840-0&aufgabe=oldest',
  };
  await openTasks(page, { items: [item, tasks[1]], storedNumber: '26-165-840-0' });
  await expect(page.locator('[data-id="oldest"] .task-archive')).toHaveText(/Mail \+ Anhänge abgelegt|Bereits abgelegt/);
  const popupPromise = page.waitForEvent('popup');
  await page.locator('[data-id="oldest"] .task-case a').click();
  const popup = await popupPromise;
  await expect(popup.locator('#vf-task-subject')).toHaveText(item.subject);
  await expect(popup.locator('#vf-task-done')).toBeEnabled();
  await expect(popup.locator('#vf-task-state')).not.toContainText('Kein Fall');
  await popup.close();
});

for (const example of [
  { number: '00-031-404193-0001', name: 'Ott Matthias', subject: 'Fwd: Schaden-Nr: 00-031-404193-0001 / Aktenzeichen: 26/0351328 / Auftrags-ID: 450421' },
  { number: '408-53-25000238-1', name: 'Xhemerson Sefa', subject: 'Fwd: Fwd: KUSS Service-Portal: Sie haben neue Unterlagen erhalten - 408-53-25000238-1' },
]) {
  test(`Versicherer-Nummer ${example.number}: bestehender benannter Fall statt Neuanlage`, async ({ page }) => {
    const helper = path.resolve(__dirname, '..', 'sv-netzwerk', 'public', 'intern', 'api', 'outlook-task-number.php');
    const number = execFileSync('php', ['-r', 'require $argv[1]; echo otCaseNumber($argv[2]);', helper, example.subject], { encoding: 'utf8' });
    expect(number).toBe(example.number);
    const item = { ...tasks[0], case_number: number, subject: example.subject, edit_url: `/intern/versicherungsfaelle/?schaden_nr=${number}&aufgabe=oldest` };
    const creations = [];
    page.context().on('request', request => {
      if (request.url().includes('action=save_case')) creations.push(request.url());
    });
    await openTasks(page, { items: [item, tasks[1]], storedNumber: example.number, storedObject: example.name });
    await expect(page.locator('[data-id="oldest"] .task-case a')).toHaveText(example.number);
    await expect(page.locator('[data-id="oldest"] .task-archive')).toHaveText(/Mail \+ Anhänge abgelegt|Bereits abgelegt/);
    const popupPromise = page.waitForEvent('popup');
    await page.locator('[data-id="oldest"] .task-case a').click();
    const popup = await popupPromise;
    await expect(popup.locator('#vf-task-subject')).toHaveText(example.subject);
    await expect(popup.locator('#vf-task-done')).toBeEnabled();
    const current = await popup.evaluate(() => JSON.parse(sessionStorage.getItem('svnet-case')));
    expect(current.folder_id).toBe('test-folder');
    expect(current.meta.vn_objekt).toBe(example.name);
    expect(current.meta.schaden_nr).toBe(example.number);
    expect(creations).toEqual([]);
    await popup.close();
  });
}
