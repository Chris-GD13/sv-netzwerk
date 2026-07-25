import QRCode from 'qrcode';
import { calculateWindowWeights } from './calculations';
import { loadAllDrafts, loadDraft, removeDraft, saveDraft } from './offline';
import {
  exportDefinitions,
  getFieldDefinition,
  portalProject,
  requiredBeforeCompletion,
  roleLabels,
  windowFormSections,
} from './schema';
import {
  apiLogin,
  apiLogout,
  apiResetPassword,
  apiListWindows,
  apiGetWindow,
  apiCreateWindow,
  apiSaveWindow,
  apiAcquireLock,
  apiReleaseLock,
  apiGetActiveLocks,
  apiGetAuditLog,
  apiListPhotos,
  apiUploadPhoto,
  apiDeletePhoto,
  apiGetCalculationParameters,
  apiLogExport,
  loadApiUser,
  onAuthChange,
} from './php-api';
import type {
  AuditLogEntry,
  DashboardStats,
  LockResult,
  PhotoItem,
  PortalRoute,
  PortalRole,
  PortalUser,
  WindowRecord,
  WindowSummary,
} from './types';

interface AppContext {
  root: HTMLElement;
  route: PortalRoute;
  recordId: string | null;
  user: PortalUser | null;
  draftDirty: boolean;
}

const LOCK_TIMEOUT_MINUTES = 15;
const SAVE_DEBOUNCE_MS = 1200;
const SYNC_WARNING_MESSAGE = 'Es liegen noch nicht synchronisierte Aenderungen vor.';

const authListeners = new Set<(user: PortalUser | null) => void>();

export async function mountInternalPortal(root: HTMLElement) {
  if (root.dataset.internalPortalMounted === 'true') return;
  root.dataset.internalPortalMounted = 'true';
  const route = (root.dataset.route as PortalRoute | undefined) ?? 'landing';
  const recordId = root.dataset.recordId || new URLSearchParams(window.location.search).get('id');
  const user = await loadApiUser();
  const context: AppContext = { root, route, recordId, user, draftDirty: false };
  const disposers: Array<() => void> = [];

  root.classList.add('intern-app');
  const unbindAuth = bindAuthListener(context);
  disposers.push(unbindAuth);
  const onlineHandler = () => void syncDraftQueue(context);
  window.addEventListener('online', onlineHandler);
  disposers.push(() => window.removeEventListener('online', onlineHandler));

  const beforeUnloadHandler = (event: BeforeUnloadEvent) => {
    if (!context.draftDirty) return;
    event.preventDefault();
    event.returnValue = SYNC_WARNING_MESSAGE;
  };
  window.addEventListener('beforeunload', beforeUnloadHandler);
  disposers.push(() => window.removeEventListener('beforeunload', beforeUnloadHandler));

  let cleanedUp = false;
  const cleanup = () => {
    if (cleanedUp) return;
    cleanedUp = true;
    while (disposers.length) {
      disposers.pop()?.();
    }
    delete root.dataset.internalPortalMounted;
  };
  window.addEventListener('pagehide', cleanup, { once: true });
  window.addEventListener('beforeunload', cleanup, { once: true });

  await renderRoute(context);
  if (navigator.onLine) void syncDraftQueue(context);
}

function bindAuthListener(context: AppContext) {
  const handleChange = async (user: PortalUser | null) => {
    context.user = user;
    if (!user && context.route !== 'login') {
      redirectTo('/intern/login/');
      return;
    }
    await renderRoute(context);
  };

  authListeners.add(handleChange);
  const unsubscribe = onAuthChange(handleChange);

  return () => {
    authListeners.delete(handleChange);
    unsubscribe();
  };
}

async function renderRoute(context: AppContext) {
  if (context.route !== 'login' && !context.user) {
    redirectTo('/intern/login/');
    return;
  }

  switch (context.route) {
    case 'landing':
      renderLanding(context);
      break;
    case 'login':
      renderLogin(context);
      break;
    case 'dashboard':
      await renderDashboard(context);
      break;
    case 'windows':
      await renderWindows(context);
      break;
    case 'record':
      await renderRecord(context);
      break;
    case 'analysis':
      await renderAnalysis(context);
      break;
    case 'export':
      await renderExport(context);
      break;
  }
}

function renderLanding(context: AppContext) {
  context.root.innerHTML = `
    <div class="intern-card intern-hero">
      <p class="sv-eyebrow">Geschuetzter Bereich</p>
      <h1>${escapeHtml(portalProject.title)}</h1>
      <p>${escapeHtml(portalProject.objectName)}<br/>${escapeHtml(portalProject.address)}</p>
      <div class="intern-actions">
        <a class="sv-button sv-button-primary" href="${context.user ? '/intern/fensterpruefung-bonn/' : '/intern/login/'}">${context.user ? 'Zum Dashboard' : 'Zur Anmeldung'}</a>
      </div>
    </div>
  `;
  if (context.user) redirectTo('/intern/fensterpruefung-bonn/');
}

function renderLogin(context: AppContext) {
  if (context.user) {
    redirectTo('/intern/fensterpruefung-bonn/');
    return;
  }

  context.root.innerHTML = `
    <div class="intern-card intern-login">
      <p class="sv-eyebrow">Anmeldung</p>
      <h1>Fensterpruefung BMVg Bonn</h1>
      <p>Der interne Bereich ist nur nach Anmeldung verfuegbar. Benutzerkonten werden ausschliesslich administrativ angelegt.</p>
      <form id="intern-login-form" class="intern-form-grid" novalidate>
        <div class="intern-field intern-field--full">
          <label for="login-email">E-Mail</label>
          <input id="login-email" name="email" type="email" autocomplete="username" required />
        </div>
        <div class="intern-field intern-field--full">
          <label for="login-password">Passwort</label>
          <input id="login-password" name="password" type="password" autocomplete="current-password" required />
        </div>
        <div class="intern-actions intern-field--full">
          <button class="sv-button sv-button-primary" type="submit">Anmelden</button>
          <button class="sv-button sv-button-secondary" type="button" id="reset-password">Passwort zuruecksetzen</button>
        </div>
      </form>
      <div id="intern-login-message"></div>
    </div>
  `;

  const form = context.root.querySelector<HTMLFormElement>('#intern-login-form');
  const message = context.root.querySelector<HTMLElement>('#intern-login-message');
  const resetButton = context.root.querySelector<HTMLButtonElement>('#reset-password');
  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!message) return;
    const email = String(new FormData(form).get('email') ?? '');
    const password = String(new FormData(form).get('password') ?? '');
    message.innerHTML = infoAlert('Anmeldung wird geprueft.');
    const { user, error } = await apiLogin(email, password);
    if (error || !user) {
      message.innerHTML = errorAlert('Anmeldung fehlgeschlagen. Bitte Zugangsdaten pruefen.');
      return;
    }
    message.innerHTML = successAlert('Anmeldung erfolgreich. Weiterleitung laeuft.');
    redirectTo('/intern/fensterpruefung-bonn/');
  });
  resetButton?.addEventListener('click', async () => {
    if (!message || !form) return;
    const email = String(new FormData(form).get('email') ?? '');
    if (!email) {
      message.innerHTML = warnAlert('Bitte zuerst die E-Mail-Adresse eingeben.');
      return;
    }
    const { error } = await apiResetPassword(email);
    message.innerHTML = error ? errorAlert('Passwort-Zuruecksetzung konnte nicht gestartet werden.') : successAlert('Passwort-Zuruecksetzung ausgeloest.');
  });
}

async function renderDashboard(context: AppContext) {
  const records = await fetchWindowSummaries(context);
  const stats = createDashboardStats(records);
  context.root.innerHTML = `
    ${renderHeader(context, 'Projekt-Dashboard', 'Live-Status fuer die laufende Fensterpruefung.')}
    <div class="intern-statusbar">
      <div class="intern-card">${connectionBadge()}</div>
      <div class="intern-card">${roleBadge(context.user?.profile.role ?? 'pruefer')}<p class="intern-meta">${escapeHtml(context.user?.profile.full_name ?? context.user?.email ?? '')}</p></div>
      <div class="intern-card"><strong>${records.length}</strong><p class="intern-meta">Datensaetze verfuegbar</p></div>
    </div>
    <div class="intern-stats">
      ${renderStat('Gesamtzahl angelegter Fenster', stats.total)}
      ${renderStat('Nicht begonnen', stats.notStarted)}
      ${renderStat('In Bearbeitung', stats.inProgress)}
      ${renderStat('Vollstaendig geprueft', stats.completed)}
      ${renderStat('Mit Mangel', stats.withDefect)}
      ${renderStat('Mit dringendem Handlungsbedarf', stats.urgent)}
      ${renderStat('Spezialpruefung erforderlich', stats.specialInspection)}
      ${renderStat('Nicht zugaenglich', stats.inaccessible)}
      ${renderStat('Heute bearbeitet', stats.touchedToday)}
    </div>
    <div class="intern-grid">
      <section class="intern-panel">
        <h2>Bearbeitungsstand je Pruefer</h2>
        <div class="intern-list">
          ${stats.byInspector.map((item) => `<div class="intern-card"><strong>${escapeHtml(item.name)}</strong><p class="intern-meta">${item.completed} abgeschlossen / ${item.total} zugewiesen</p></div>`).join('') || '<div class="intern-empty">Noch keine Zuordnungen.</div>'}
        </div>
      </section>
      <section class="intern-panel">
        <h2>Letzte Aenderungen</h2>
        <div class="intern-list">
          ${stats.recentChanges.map((item) => `<a class="intern-card" href="/intern/fensterpruefung-bonn/fenster/${encodeURIComponent(item.id)}/"><strong>${escapeHtml(item.label)}</strong><p class="intern-meta">${formatDateTime(item.updatedAt)} · ${escapeHtml(item.status)}${item.user ? ` · ${escapeHtml(item.user)}` : ''}</p></a>`).join('') || '<div class="intern-empty">Noch keine Aenderungen protokolliert.</div>'}
        </div>
      </section>
    </div>
  `;
  subscribeToWindowChanges(context, () => void renderDashboard(context));
}

async function renderWindows(context: AppContext) {
  const records = await fetchWindowSummaries(context);
  const filtersHtml = createFilterControls(records);
  context.root.innerHTML = `
    ${renderHeader(context, 'Fensterdatensaetze', 'Suche, Filter, Datensatzsperren und Schnellzugriffe.')}
    <div class="intern-toolbar">
      <div class="intern-search">
        <label for="window-search">Suche</label>
        <input id="window-search" type="search" placeholder="Fensternummer, Raum, Gebaeudeteil oder Kennzeichnung" />
      </div>
      ${filtersHtml}
      <div class="intern-actions">
        <button class="sv-button sv-button-primary" type="button" id="create-window">Fenster anlegen</button>
        <button class="sv-button sv-button-secondary" type="button" id="download-qr-list">QR-Code-Liste</button>
      </div>
    </div>
    <div id="window-list-container">${renderWindowTable(records)}</div>
  `;
  const listContainer = context.root.querySelector<HTMLElement>('#window-list-container');
  const search = context.root.querySelector<HTMLInputElement>('#window-search');
  const selects = Array.from(context.root.querySelectorAll<HTMLSelectElement>('[data-filter-key]'));
  const applyFilters = () => {
    const query = search?.value.trim().toLowerCase() ?? '';
    const filtered = records.filter((record) => {
      const matchQuery = !query || [record.window_number, record.room_number, record.section_label, record.room_label, record.record_id]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(query));
      const matchFilters = selects.every((select) => {
        const key = select.dataset.filterKey;
        if (!key || !select.value) return true;
        return String((record as unknown as Record<string, unknown>)[key] ?? '') === select.value;
      });
      return matchQuery && matchFilters;
    });
    if (listContainer) listContainer.innerHTML = renderWindowTable(filtered);
    bindWindowTableActions(context, filtered);
  };
  search?.addEventListener('input', applyFilters);
  selects.forEach((select) => select.addEventListener('change', applyFilters));
  context.root.querySelector<HTMLButtonElement>('#create-window')?.addEventListener('click', async () => {
    const created = await createWindowRecord(context, null);
    if (created) redirectTo(`/intern/fensterpruefung-bonn/fenster/${encodeURIComponent(created.id)}/`);
  });
  context.root.querySelector<HTMLButtonElement>('#download-qr-list')?.addEventListener('click', async () => {
    await downloadQrOverview(records);
  });
  bindWindowTableActions(context, records);
  subscribeToWindowChanges(context, () => void renderWindows(context));
}

function bindWindowTableActions(context: AppContext, records: WindowSummary[]) {
  context.root.querySelectorAll<HTMLElement>('[data-open-window]').forEach((button) => {
    button.onclick = () => {
      const id = button.dataset.openWindow;
      if (id) redirectTo(`/intern/fensterpruefung-bonn/fenster/${encodeURIComponent(id)}/`);
    };
  });
  context.root.querySelectorAll<HTMLElement>('[data-duplicate-window]').forEach((button) => {
    button.onclick = async () => {
      const id = button.dataset.duplicateWindow;
      const source = records.find((record) => record.id === id);
      if (!source) return;
      const created = await createWindowRecord(context, source.id);
      if (created) redirectTo(`/intern/fensterpruefung-bonn/fenster/${encodeURIComponent(created.id)}/`);
    };
  });
}

async function renderRecord(context: AppContext) {
  const id = context.recordId ?? readRecordIdFromPath();
  if (!id) {
    context.root.innerHTML = warnAlert('Kein Fensterdatensatz ausgewaehlt.');
    return;
  }
  const payload = await fetchWindowRecord(context, id);
  if (!payload) {
    context.root.innerHTML = errorAlert('Fensterdatensatz konnte nicht geladen werden.');
    return;
  }

  const draft = await loadDraft(id);
  const record = mergeRecordWithDraft(payload, draft);
  const canEdit = canEditRecord(context.user?.profile.role ?? 'pruefer', record);
  const lock = canEdit ? await acquireLock(context, id) : null;
  const auditLogs = await fetchAuditLogs(context, id);
  const photos = await fetchPhotos(context, id);
  const calculationParameters = await fetchCalculationParameters(context);
  const calculated = calculateWindowWeights(record.form_data, calculationParameters);
  record.calculated_data = { ...record.calculated_data, ...calculated };

  context.root.innerHTML = `
    ${renderHeader(context, `Fenster ${escapeHtml(record.window_number || record.record_id)}`, 'Strukturierte Erfassung, Autosave, Audit-Log und Fotodokumentation.')}
    ${lock && !lock.ok ? warnAlert(lock.message ?? 'Datensatz ist derzeit gesperrt.') : ''}
    <div class="intern-grid">
      <div>
        <div class="intern-statusbar">
          <div class="intern-card">${connectionBadge()}</div>
          <div class="intern-card"><strong>${Math.round(record.progress_percent)}%</strong><p class="intern-meta">Fortschritt</p></div>
          <div class="intern-card"><strong>${escapeHtml(record.status)}</strong><p class="intern-meta">Status</p></div>
          <div class="intern-card"><strong>${formatDateTime(record.updated_at)}</strong><p class="intern-meta">Letzte Aenderung</p></div>
        </div>
        <form id="window-record-form" class="intern-list" novalidate>
          ${windowFormSections.map((section) => renderFormSection(section, record.form_data, record.calculated_data, !canEdit || Boolean(lock && !lock.ok))).join('')}
        </form>
        <section class="intern-form-section">
          <h2>I. Fotodokumentation</h2>
          <div class="intern-upload">
            <label for="photo-category">Fotokategorie</label>
            <select id="photo-category"></select>
            <label for="photo-caption">Bildbeschreibung</label>
            <input id="photo-caption" type="text" />
            <label for="photo-files">Fotos aufnehmen oder auswaehlen</label>
            <input id="photo-files" type="file" accept="image/*" capture="environment" multiple ${!canEdit || Boolean(lock && !lock.ok) ? 'disabled' : ''} />
            <div class="intern-actions"><button class="sv-button sv-button-secondary" type="button" id="upload-photos" ${!canEdit || Boolean(lock && !lock.ok) ? 'disabled' : ''}>Fotos hochladen</button></div>
          </div>
          <div id="photo-gallery" class="intern-photo-grid">${renderPhotos(photos)}</div>
        </section>
      </div>
      <aside class="intern-list">
        <section class="intern-panel">
          <h2>Gewichtsermittlung</h2>
          <div class="intern-list">
            <div class="intern-card"><strong>${formatNumber(calculated.glassWeightKg)} kg</strong><p class="intern-meta">Rechnerisches Glasgewicht</p></div>
            <div class="intern-card"><strong>${formatNumber(calculated.frameWeightKg)} kg</strong><p class="intern-meta">Geschaetztes Rahmengewicht</p></div>
            <div class="intern-card"><strong>${formatNumber(calculated.totalWingWeightKg)} kg</strong><p class="intern-meta">Gesamtfluegelgewicht</p></div>
            <div class="intern-card"><strong>${formatNumber(calculated.appliedTestWeightKg)} kg</strong><p class="intern-meta">Angesetztes Pruefgewicht</p></div>
          </div>
        </section>
        <section class="intern-panel">
          <h2>Audit-Log</h2>
          <div class="intern-list">${renderAuditLogs(auditLogs)}</div>
        </section>
        <section class="intern-panel">
          <h2>QR-Code</h2>
          <canvas id="record-qr" aria-label="QR-Code fuer diesen Datensatz"></canvas>
          <p class="intern-meta">Direktlink nach dem Login auf diesen Datensatz.</p>
        </section>
      </aside>
    </div>
    <div class="intern-sticky-actions">
      <div class="intern-progress">
        <a class="sv-button sv-button-secondary" href="/intern/fensterpruefung-bonn/fenster/">Zurueck</a>
        <progress value="${Math.round(record.progress_percent)}" max="100"></progress>
        <span>${Math.round(record.progress_percent)}% Pflichtfelder</span>
      </div>
      <div class="intern-actions">
        <button class="sv-button sv-button-secondary" type="button" id="save-draft" ${!canEdit || Boolean(lock && !lock.ok) ? 'disabled' : ''}>Zwischenspeichern</button>
        <button class="sv-button sv-button-primary" type="button" id="complete-record" ${!canEdit || Boolean(lock && !lock.ok) ? 'disabled' : ''}>Pruefung abschliessen</button>
        <button class="sv-button sv-button-ghost" type="button" id="logout-button">Abmelden</button>
      </div>
    </div>
  `;

  fillPhotoCategories(context.root.querySelector<HTMLSelectElement>('#photo-category'));
  const form = context.root.querySelector<HTMLFormElement>('#window-record-form');
  const saveButton = context.root.querySelector<HTMLButtonElement>('#save-draft');
  const completeButton = context.root.querySelector<HTMLButtonElement>('#complete-record');
  const logoutButton = context.root.querySelector<HTMLButtonElement>('#logout-button');
  const gallery = context.root.querySelector<HTMLElement>('#photo-gallery');
  const qrCanvas = context.root.querySelector<HTMLCanvasElement>('#record-qr');
  if (qrCanvas) {
    await QRCode.toCanvas(qrCanvas, `${window.location.origin}/intern/fensterpruefung-bonn/fenster/${encodeURIComponent(id)}/`, {
      width: 220,
      color: { dark: '#071a2e', light: '#ffffff' },
      margin: 1,
    });
  }

  const workingCopy = structuredClone(record.form_data) as Record<string, unknown>;
  const scheduleSave = debounce(async () => {
    context.draftDirty = true;
    await persistDraft(id, workingCopy, record.calculated_data, form);
    if (navigator.onLine && context.user && canEdit && (!lock || lock.ok)) {
      await saveWindow(context, record, workingCopy, false);
    }
  }, SAVE_DEBOUNCE_MS);

  form?.addEventListener('input', async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement)) return;
    const name = target.name;
    workingCopy[name] = target instanceof HTMLInputElement && target.type === 'checkbox' ? target.checked : target.value;
    if (name === 'manual_weight_override' && target instanceof HTMLInputElement && !target.checked) workingCopy.manual_override_reason = '';
    const recalculated = calculateWindowWeights(workingCopy, calculationParameters);
    record.calculated_data = { ...record.calculated_data, ...recalculated };
    scheduleSave();
  });

  saveButton?.addEventListener('click', async () => {
    await persistDraft(id, workingCopy, record.calculated_data, form);
    if (navigator.onLine && context.user && canEdit && (!lock || lock.ok)) await saveWindow(context, record, workingCopy, true);
  });

  completeButton?.addEventListener('click', async () => {
    const missing = requiredBeforeCompletion.filter((field) => isMissing(workingCopy[field]));
    if (missing.length) {
      alert(`Abschluss noch nicht moeglich. Bitte Pflichtfelder fuellen: ${missing.map((field) => getFieldDefinition(field)?.label ?? field).join(', ')}`);
      return;
    }
    const summary = summarizeCompletion(workingCopy, record.calculated_data);
    if (!window.confirm(`Pruefung abschliessen?\n\n${summary}`)) return;
    workingCopy.status = 'Pruefung abgeschlossen';
    workingCopy.completion_confirmed = true;
    await persistDraft(id, workingCopy, record.calculated_data, form);
    await saveWindow(context, record, workingCopy, true);
    await renderRecord(context);
  });

  logoutButton?.addEventListener('click', async () => {
    await apiLogout();
    redirectTo('/intern/login/');
  });

  context.root.querySelector<HTMLButtonElement>('#upload-photos')?.addEventListener('click', async () => {
    const fileInput = context.root.querySelector<HTMLInputElement>('#photo-files');
    const categorySelect = context.root.querySelector<HTMLSelectElement>('#photo-category');
    const captionInput = context.root.querySelector<HTMLInputElement>('#photo-caption');
    if (!fileInput?.files?.length || !categorySelect || !gallery) return;
    const uploaded = await uploadPhotos(context, id, Array.from(fileInput.files), categorySelect.value, captionInput?.value ?? '');
    gallery.innerHTML = renderPhotos(uploaded);
    bindPhotoDeletion(context, id, gallery);
    fileInput.value = '';
    if (captionInput) captionInput.value = '';
  });

  bindPhotoDeletion(context, id, gallery ?? undefined);
  subscribeToSingleRecord(context, id, async () => {
    const note = context.root.querySelector<HTMLElement>('[data-record-refresh-note]');
    if (note) note.remove();
    context.root.prepend(createNotice('Der Datensatz wurde zwischenzeitlich geaendert. Bitte pruefen und neu laden.', 'warn', true));
  });

  activateLockMaintenance(context, id);
}

async function renderAnalysis(context: AppContext) {
  const records = await fetchWindowSummaries(context);
  const groupings = groupBy(records, (item) => item.building_label || 'Unbekannt');
  const byFloor = groupBy(records, (item) => item.floor_label || 'Unbekannt');
  const byInspector = groupBy(records, (item) => item.assigned_name || 'Nicht zugewiesen');
  const bySystem = groupBy(records, (item) => String((item as unknown as { form_data?: Record<string, unknown> }).form_data?.window_system ?? 'Nicht erfasst'));
  context.root.innerHTML = `
    ${renderHeader(context, 'Auswertung', 'Interne Uebersichten fuer Status, Eignung und Prioritaeten.')}
    <div class="intern-analysis-grid">
      ${renderAnalysisCard('Gepruefte Fenster', records.filter((record) => record.status === 'Pruefung abgeschlossen' || record.status === 'freigegeben').length)}
      ${renderAnalysisCard('Ungepruefte Fenster', records.filter((record) => record.status === 'nicht begonnen').length)}
      ${renderAnalysisCard('Nicht zugaengliche Fenster', records.filter((record) => record.accessibility_status === 'nicht zugaenglich').length)}
      ${renderAnalysisCard('Geeignete Beschlaege', records.filter((record) => record.overall_rating === 'ohne festgestellten Handlungsbedarf').length)}
      ${renderAnalysisCard('Nicht geeignete Beschlaege', records.filter((record) => record.has_defect).length)}
      ${renderAnalysisCard('Spezialpruefungen', records.filter((record) => record.special_inspection_required).length)}
      ${renderAnalysisCard('Dringende Sicherungsmassnahmen', records.filter((record) => record.urgent_action_required || record.danger_immediate).length)}
    </div>
    <div class="intern-grid">
      <section class="intern-panel"><h2>Ergebnisse je Gebaeude</h2>${renderGrouping(groupings)}</section>
      <section class="intern-panel"><h2>Ergebnisse je Etage</h2>${renderGrouping(byFloor)}</section>
      <section class="intern-panel"><h2>Ergebnisse je Pruefer</h2>${renderGrouping(byInspector)}</section>
      <section class="intern-panel"><h2>Ergebnisse je Fenstersystem</h2>${renderGrouping(bySystem)}</section>
    </div>
  `;
}

async function renderExport(context: AppContext) {
  const records = await fetchWindowSummaries(context);
  context.root.innerHTML = `
    ${renderHeader(context, 'Export', 'CSV-, PDF- und Management-Ausgaben fuer Auswertung und Berichtswesen.')}
    <div class="intern-export-grid">
      ${exportDefinitions.map((item) => `
        <article class="intern-export-card">
          <h2>${escapeHtml(item.title)}</h2>
          <p>${escapeHtml(item.description)}</p>
          <div class="intern-actions">
            <button class="sv-button sv-button-primary" type="button" data-export-id="${escapeHtml(item.id)}">Export erzeugen</button>
          </div>
        </article>
      `).join('')}
      <article class="intern-export-card">
        <h2>PDF-Einzelprotokoll</h2>
        <p>Direkt aus einem Fensterdatensatz per Browser-Druckansicht erzeugbar.</p>
      </article>
      <article class="intern-export-card">
        <h2>PDF-Sammelprotokoll</h2>
        <p>Erzeugt eine druckfaehige Sammelansicht der gefilterten Datensaetze.</p>
        <div class="intern-actions"><button class="sv-button sv-button-secondary" type="button" id="print-summary">Druckansicht oeffnen</button></div>
      </article>
    </div>
  `;
  context.root.querySelectorAll<HTMLElement>('[data-export-id]').forEach((button) => {
    button.onclick = async () => {
      const exportId = button.dataset.exportId;
      if (!exportId) return;
      await exportRecords(context, exportId, records);
    };
  });
  context.root.querySelector<HTMLButtonElement>('#print-summary')?.addEventListener('click', async () => {
    await printSummary(records);
  });
}

async function fetchWindowSummaries(_context: AppContext): Promise<WindowSummary[]> {
  const records = await apiListWindows();
  const locks = await apiGetActiveLocks();
  return records.map((item) => {
    const lock = locks.get(String(item.id));
    return {
      ...item,
      id: String(item.id),
      record_id: String(item.record_id),
      lock_owner_id: lock?.owner_id ?? null,
      lock_owner_name: lock?.owner_name ?? null,
      lock_expires_at: lock?.expires_at ?? null,
    };
  });
}

async function fetchWindowRecord(_context: AppContext, id: string): Promise<WindowRecord | null> {
  return apiGetWindow(id);
}

function mergeRecordWithDraft(record: WindowRecord, draft: Awaited<ReturnType<typeof loadDraft>>) {
  if (!draft) return record;
  return {
    ...record,
    form_data: { ...record.form_data, ...draft.data },
    calculated_data: { ...record.calculated_data, ...draft.calculatedData },
    last_saved_locally_at: draft.updatedAt,
  };
}

async function fetchAuditLogs(_context: AppContext, windowId: string): Promise<AuditLogEntry[]> {
  return apiGetAuditLog(windowId);
}

async function fetchPhotos(_context: AppContext, windowId: string): Promise<PhotoItem[]> {
  return apiListPhotos(windowId);
}

async function fetchCalculationParameters(_context: AppContext) {
  return apiGetCalculationParameters();
}

function createDashboardStats(records: WindowSummary[]): DashboardStats {
  const today = new Date().toISOString().slice(0, 10);
  const byInspectorMap = new Map<string, { id: string; name: string; total: number; completed: number }>();
  records.forEach((record) => {
    const key = record.assigned_to ?? record.assigned_name ?? 'unassigned';
    const current = byInspectorMap.get(key) ?? { id: key, name: record.assigned_name ?? 'Nicht zugewiesen', total: 0, completed: 0 };
    current.total += 1;
    if (record.status === 'Pruefung abgeschlossen' || record.status === 'fachlich geprueft' || record.status === 'freigegeben') current.completed += 1;
    byInspectorMap.set(key, current);
  });
  return {
    total: records.length,
    notStarted: records.filter((item) => item.status === 'nicht begonnen').length,
    inProgress: records.filter((item) => item.status === 'in Bearbeitung' || item.status === 'Pruefung unterbrochen').length,
    completed: records.filter((item) => item.status === 'Pruefung abgeschlossen' || item.status === 'fachlich geprueft' || item.status === 'freigegeben').length,
    withDefect: records.filter((item) => item.has_defect).length,
    urgent: records.filter((item) => item.urgent_action_required).length,
    specialInspection: records.filter((item) => item.special_inspection_required).length,
    inaccessible: records.filter((item) => item.accessibility_status === 'nicht zugaenglich').length,
    touchedToday: records.filter((item) => item.updated_at.slice(0, 10) === today).length,
    byInspector: Array.from(byInspectorMap.values()).sort((left, right) => right.total - left.total),
    recentChanges: records
      .slice()
      .sort((left, right) => right.updated_at.localeCompare(left.updated_at))
      .slice(0, 8)
      .map((item) => ({
        id: item.id,
        label: item.window_number || item.record_id,
        updatedAt: item.updated_at,
        user: item.assigned_name,
        status: item.status,
      })),
  };
}

function renderHeader(context: AppContext, title: string, text: string) {
  return `
    <div class="intern-card intern-hero">
      <p class="sv-eyebrow">${escapeHtml(portalProject.title)}</p>
      <h1>${escapeHtml(title)}</h1>
      <p>${escapeHtml(text)}</p>
      <div class="intern-actions">
        <a class="sv-button sv-button-secondary" href="/intern/fensterpruefung-bonn/">Dashboard</a>
        <a class="sv-button sv-button-secondary" href="/intern/fensterpruefung-bonn/fenster/">Fenster</a>
        <a class="sv-button sv-button-secondary" href="/intern/fensterpruefung-bonn/auswertung/">Auswertung</a>
        <a class="sv-button sv-button-secondary" href="/intern/fensterpruefung-bonn/export/">Export</a>
        ${context.user?.profile.role === 'administrator' ? '<span class="intern-badge intern-badge--info">Administrator kann Sperren aufheben und Freigaben aendern.</span>' : ''}
      </div>
    </div>
  `;
}

function renderStat(label: string, value: number) {
  return `<article class="intern-stat"><span>${escapeHtml(label)}</span><strong>${value}</strong></article>`;
}

function createFilterControls(records: WindowSummary[]) {
  const filterKeys: Array<keyof Pick<WindowSummary, 'building_label' | 'section_label' | 'floor_label' | 'assigned_name' | 'status' | 'overall_rating'>> = [
    'building_label',
    'section_label',
    'floor_label',
    'assigned_name',
    'status',
    'overall_rating',
  ];
  return filterKeys.map((key) => {
    const values = Array.from(new Set(records.map((record) => record[key]).filter(Boolean))).sort();
    return `
      <div class="intern-field">
        <label for="filter-${String(key)}">${escapeHtml(filterLabel(key))}</label>
        <select id="filter-${String(key)}" data-filter-key="${String(key)}">
          <option value="">Alle</option>
          ${values.map((value) => `<option value="${escapeHtml(String(value))}">${escapeHtml(String(value))}</option>`).join('')}
        </select>
      </div>
    `;
  }).join('');
}

function filterLabel(key: string) {
  switch (key) {
    case 'building_label': return 'Gebaeude';
    case 'section_label': return 'Gebaeudeteil';
    case 'floor_label': return 'Etage';
    case 'assigned_name': return 'Pruefer';
    case 'status': return 'Pruefstatus';
    case 'overall_rating': return 'Bewertung';
    default: return key;
  }
}

function renderWindowTable(records: WindowSummary[]) {
  if (!records.length) return '<div class="intern-empty">Keine Datensaetze gefunden.</div>';
  return `
    <div class="intern-table-wrap">
      <table class="intern-table">
        <thead>
          <tr>
            <th>Fenster</th>
            <th>Standort</th>
            <th>Status</th>
            <th>Pruefer</th>
            <th>Letzte Aenderung</th>
            <th>Sperre</th>
            <th>Aktionen</th>
          </tr>
        </thead>
        <tbody>
          ${records.map((record) => `
            <tr>
              <td><strong>${escapeHtml(record.window_number || record.record_id)}</strong><br/><span class="intern-meta">${escapeHtml(record.record_id)}</span></td>
              <td>${escapeHtml([record.building_label, record.section_label, record.floor_label, record.room_number].filter(Boolean).join(' · '))}</td>
              <td>${escapeHtml(record.status)}${record.special_inspection_required ? '<br/><span class="intern-badge intern-badge--warn">Spezialpruefung</span>' : ''}${record.urgent_action_required ? '<br/><span class="intern-badge intern-badge--danger">Sofort</span>' : ''}</td>
              <td>${escapeHtml(record.assigned_name ?? '—')}</td>
              <td>${formatDateTime(record.updated_at)}</td>
              <td>${record.lock_owner_name ? `<span class="intern-badge intern-badge--info">${escapeHtml(record.lock_owner_name)} bis ${formatTime(record.lock_expires_at)}</span>` : '<span class="intern-badge intern-badge--ok">frei</span>'}</td>
              <td>
                <div class="intern-actions">
                  <button type="button" class="intern-inline-button" data-open-window="${escapeHtml(record.id)}">Oeffnen</button>
                  <button type="button" class="intern-inline-button" data-duplicate-window="${escapeHtml(record.id)}">Duplizieren</button>
                </div>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function renderFormSection(
  section: { id: string; title: string; description?: string; fields: Array<{ id: string; label: string; type: string; options?: Array<{ value: string; label: string }>; required?: boolean; step?: string; min?: number; max?: number; placeholder?: string }> },
  values: Record<string, unknown>,
  calculated: Record<string, unknown>,
  disabled: boolean,
) {
  return `
    <section class="intern-form-section" id="section-${escapeHtml(section.id)}">
      <h2>${escapeHtml(section.title)}</h2>
      ${section.description ? `<p class="intern-meta">${escapeHtml(section.description)}</p>` : ''}
      <div class="intern-form-grid">
        ${section.fields.map((field) => renderField(field, values, calculated, disabled)).join('')}
      </div>
    </section>
  `;
}

function renderField(
  field: { id: string; label: string; type: string; options?: Array<{ value: string; label: string }>; required?: boolean; step?: string; min?: number; max?: number; placeholder?: string },
  values: Record<string, unknown>,
  calculated: Record<string, unknown>,
  disabled: boolean,
) {
  const value = values[field.id] ?? calculatedMapping(field.id, calculated) ?? '';
  const required = field.required ? 'required' : '';
  const disabledAttr = disabled ? 'disabled' : '';
  const fullWidth = field.type === 'textarea' ? 'intern-field--full' : '';

  if (field.type === 'checkbox') {
    return `
      <div class="intern-field ${fullWidth}">
        <label class="intern-checkbox">
          <input type="checkbox" name="${escapeHtml(field.id)}" ${Boolean(value) ? 'checked' : ''} ${disabledAttr} />
          <span>${escapeHtml(field.label)}${field.required ? ' *' : ''}</span>
        </label>
      </div>
    `;
  }

  if (field.type === 'select') {
    return `
      <div class="intern-field ${fullWidth}">
        <label for="${escapeHtml(field.id)}">${escapeHtml(field.label)}${field.required ? ' *' : ''}</label>
        <select id="${escapeHtml(field.id)}" name="${escapeHtml(field.id)}" ${required} ${disabledAttr}>
          <option value="">Bitte waehlen</option>
          ${(field.options ?? []).map((option) => `<option value="${escapeHtml(option.value)}" ${String(value) === option.value ? 'selected' : ''}>${escapeHtml(option.label)}</option>`).join('')}
        </select>
      </div>
    `;
  }

  if (field.type === 'textarea') {
    return `
      <div class="intern-field intern-field--full">
        <label for="${escapeHtml(field.id)}">${escapeHtml(field.label)}${field.required ? ' *' : ''}</label>
        <textarea id="${escapeHtml(field.id)}" name="${escapeHtml(field.id)}" ${required} ${disabledAttr}>${escapeHtml(String(value))}</textarea>
      </div>
    `;
  }

  return `
    <div class="intern-field ${fullWidth}">
      <label for="${escapeHtml(field.id)}">${escapeHtml(field.label)}${field.required ? ' *' : ''}</label>
      <input id="${escapeHtml(field.id)}" name="${escapeHtml(field.id)}" type="${escapeHtml(field.type)}" value="${escapeHtml(String(value))}" ${field.step ? `step="${escapeHtml(field.step)}"` : ''} ${typeof field.min === 'number' ? `min="${field.min}"` : ''} ${typeof field.max === 'number' ? `max="${field.max}"` : ''} ${field.placeholder ? `placeholder="${escapeHtml(field.placeholder)}"` : ''} ${required} ${disabledAttr} />
    </div>
  `;
}

function calculatedMapping(fieldId: string, calculated: Record<string, unknown>) {
  switch (fieldId) {
    case 'glass_weight_kg': return calculated.glassWeightKg;
    case 'estimated_frame_weight_kg': return calculated.frameWeightKg;
    case 'total_wing_weight_kg': return calculated.totalWingWeightKg;
    case 'applied_test_weight_kg': return calculated.appliedTestWeightKg;
    default: return null;
  }
}

async function persistDraft(windowId: string, data: Record<string, unknown>, calculatedData: Record<string, unknown>, form: HTMLFormElement | null) {
  const unsyncedChanges = Array.from(form?.querySelectorAll<HTMLElement>('input, select, textarea') ?? []).map((element) => element.getAttribute('name') ?? '').filter(Boolean);
  await saveDraft({ windowId, data: structuredClone(data), calculatedData: structuredClone(calculatedData), updatedAt: new Date().toISOString(), unsyncedChanges });
}

async function saveWindow(context: AppContext, record: WindowRecord, data: Record<string, unknown>, explicitSave: boolean) {
  if (!context.user) return;
  const { error } = await apiSaveWindow(record.id, data, record.calculated_data);
  if (error) {
    showInlineMessage(context.root, errorAlert('Datensatz konnte nicht gespeichert werden.'));
    return;
  }
  await removeDraft(record.id);
  context.draftDirty = false;
  if (explicitSave) showInlineMessage(context.root, successAlert('Datensatz wurde gespeichert.'));
}

function deriveSummaryColumns(id: string, data: Record<string, unknown>, user: PortalUser) {
  const completed = requiredBeforeCompletion.filter((field) => !isMissing(data[field])).length;
  return {
    id,
    progressPercent: Math.round((completed / requiredBeforeCompletion.length) * 100),
    assignedName: user.profile.full_name ?? user.email,
  };
}

function hasDefect(data: Record<string, unknown>) {
  return [
    'hinge_fastening_loose',
    'hinge_screws_missing',
    'hinge_deformation',
    'hinge_corrosion',
    'hinge_damage',
    'scissor_fastening_loose',
    'scissor_deformation',
    'scissor_corrosion',
    'scissor_damage',
    'wing_scrapes',
    'wing_hangs',
    'unsafe_until_repair',
  ].some((field) => Boolean(data[field]));
}

async function createWindowRecord(context: AppContext, sourceId: string | null) {
  if (!context.user) return null;
  let formData: Record<string, unknown> = {
    status: 'nicht begonnen',
    inspection_date: new Date().toISOString().slice(0, 10),
    inspector_name: context.user.profile.full_name ?? context.user.email,
  };
  if (sourceId) {
    const source = await fetchWindowRecord(context, sourceId);
    if (source) {
      formData = { ...source.form_data, status: 'vorbereitet', completion_confirmed: false };
      delete formData.release_reason;
    }
  }
  return apiCreateWindow(formData);
}

async function acquireLock(context: AppContext, windowId: string): Promise<LockResult | null> {
  return apiAcquireLock(windowId, LOCK_TIMEOUT_MINUTES);
}

function activateLockMaintenance(context: AppContext, windowId: string) {
  let lastActivity = Date.now();
  const markActivity = () => { lastActivity = Date.now(); };
  document.addEventListener('pointerdown', markActivity, { passive: true });
  document.addEventListener('keydown', markActivity);
  const interval = window.setInterval(async () => {
    const inactiveMinutes = (Date.now() - lastActivity) / 1000 / 60;
    if (inactiveMinutes >= LOCK_TIMEOUT_MINUTES) {
      await releaseLock(context, windowId);
      window.clearInterval(interval);
      return;
    }
    await apiAcquireLock(windowId, LOCK_TIMEOUT_MINUTES);
  }, 60_000);
  window.addEventListener('beforeunload', () => void releaseLock(context, windowId), { once: true });
}

async function releaseLock(context: AppContext, windowId: string) {
  await apiReleaseLock(windowId);
}

async function uploadPhotos(context: AppContext, windowId: string, files: File[], category: string, caption: string) {
  for (const file of files) {
    const resized = await resizeImageIfNeeded(file);
    await apiUploadPhoto(windowId, resized, category, caption);
  }
  return fetchPhotos(context, windowId);
}

function bindPhotoDeletion(context: AppContext, windowId: string, scope?: ParentNode) {
  scope?.querySelectorAll<HTMLElement>('[data-delete-photo]').forEach((button) => {
    button.onclick = async () => {
      if (!window.confirm('Foto wirklich loeschen?')) return;
      const id = button.dataset.deletePhoto;
      if (!id) return;
      await apiDeletePhoto(id);
      const gallery = context.root.querySelector<HTMLElement>('#photo-gallery');
      if (gallery) gallery.innerHTML = renderPhotos(await fetchPhotos(context, windowId));
      bindPhotoDeletion(context, windowId, gallery ?? undefined);
    };
  });
}

function renderPhotos(photos: PhotoItem[]) {
  if (!photos.length) return '<div class="intern-empty">Noch keine Fotos gespeichert.</div>';
  return photos.map((photo) => `
    <article class="intern-photo-item">
      <img alt="${escapeHtml(photo.category)}" src="${escapeHtml(createPhotoPlaceholder(photo.category))}" />
      <strong>${escapeHtml(photo.category)}</strong>
      <p class="intern-meta">${escapeHtml(photo.caption ?? photo.file_name)}</p>
      <p class="intern-meta">${photo.inspector_name ? escapeHtml(photo.inspector_name) : '—'} · ${photo.taken_at ? formatDateTime(photo.taken_at) : '—'}</p>
      <button type="button" class="intern-inline-button" data-delete-photo="${escapeHtml(photo.id)}" data-storage-path="${escapeHtml(photo.storage_path)}">Loeschen</button>
    </article>
  `).join('');
}

async function resizeImageIfNeeded(file: File) {
  if (file.size <= 1_800_000) return file;
  const bitmap = await createImageBitmap(file);
  const maxWidth = 1800;
  const scale = Math.min(1, maxWidth / bitmap.width);
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  const context = canvas.getContext('2d');
  if (!context) return file;
  context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82));
  return blob ? new File([blob], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' }) : file;
}

async function syncDraftQueue(context: AppContext) {
  if (!context.user) return;
  const drafts = await loadAllDrafts();
  for (const draft of drafts) {
    const record = await fetchWindowRecord(context, draft.windowId);
    if (!record) continue;
    record.calculated_data = draft.calculatedData;
    await saveWindow(context, record, draft.data, false);
  }
}

async function exportRecords(context: AppContext, exportId: string, records: WindowSummary[]) {
  const definition = exportDefinitions.find((item) => item.id === exportId);
  if (!definition) return;
  const rows = records.filter(definition.filter);
  const delimiter = exportId === 'excel-all' ? ';' : ',';
  const header = ['Datensatz', 'Fensternummer', 'Gebaeude', 'Gebaeudeteil', 'Etage', 'Raumnummer', 'Status', 'Bewertung', 'Prioritaet', 'Pruefer', 'Letzte Aenderung'];
  const csv = [header.join(delimiter), ...rows.map((record) => [
    record.record_id,
    record.window_number,
    record.building_label ?? '',
    record.section_label ?? '',
    record.floor_label ?? '',
    record.room_number ?? '',
    record.status,
    record.overall_rating ?? '',
    record.priority ?? '',
    record.assigned_name ?? '',
    record.updated_at,
  ].map((value) => quoteCsv(value, delimiter)).join(delimiter))].join('\n');
  downloadBlob(`${definition.id}-${new Date().toISOString().slice(0, 10)}.csv`, csv, 'text/csv;charset=utf-8');
  void apiLogExport(definition.title, `${definition.id}.csv`, { exportId, rowCount: rows.length });
}

async function printSummary(records: WindowSummary[]) {
  const popup = window.open('', '_blank', 'noopener,noreferrer,width=1200,height=900');
  if (!popup) return;
  popup.document.write(`
    <html lang="de"><head><title>Fensterpruefung BMVg Bonn – Sammelprotokoll</title><style>
      body{font-family:Arial,sans-serif;padding:24px;color:#071a2e}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d6e0e8;padding:8px;text-align:left}h1{margin-top:0}
    </style></head><body>
    <h1>${escapeHtml(portalProject.title)} – Sammelprotokoll</h1>
    <p>Datenstand: ${escapeHtml(new Date().toLocaleString('de-DE'))}</p>
    <table><thead><tr><th>Fenster</th><th>Standort</th><th>Status</th><th>Bewertung</th><th>Prioritaet</th></tr></thead><tbody>
    ${records.map((record) => `<tr><td>${escapeHtml(record.window_number || record.record_id)}</td><td>${escapeHtml([record.building_label, record.section_label, record.floor_label, record.room_number].filter(Boolean).join(' · '))}</td><td>${escapeHtml(record.status)}</td><td>${escapeHtml(record.overall_rating ?? '')}</td><td>${escapeHtml(record.priority ?? '')}</td></tr>`).join('')}
    </tbody></table></body></html>
  `);
  popup.document.close();
  popup.focus();
  popup.print();
}

async function downloadQrOverview(records: WindowSummary[]) {
  const popup = window.open('', '_blank', 'noopener,noreferrer,width=1200,height=900');
  if (!popup) return;
  popup.document.write('<html lang="de"><head><title>QR-Codes Fensterpruefung</title><style>body{font-family:Arial,sans-serif;padding:24px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px}.card{border:1px solid #d6e0e8;border-radius:12px;padding:16px}</style></head><body><h1>QR-Code-Liste</h1><div class="grid" id="grid"></div></body></html>');
  popup.document.close();
  const grid = popup.document.getElementById('grid');
  if (!grid) return;
  for (const record of records) {
    const card = popup.document.createElement('div');
    card.className = 'card';
    const canvas = popup.document.createElement('canvas');
    await QRCode.toCanvas(canvas, `${window.location.origin}/intern/fensterpruefung-bonn/fenster/${encodeURIComponent(record.id)}/`, { width: 160, margin: 1 });
    card.innerHTML = `<strong>${escapeHtml(record.window_number || record.record_id)}</strong><p>${escapeHtml([record.room_number, record.floor_label, record.section_label].filter(Boolean).join(' · '))}</p>`;
    card.append(canvas);
    grid.append(card);
  }
  popup.focus();
  popup.print();
}

function renderAuditLogs(entries: AuditLogEntry[]) {
  if (!entries.length) return '<div class="intern-empty">Noch keine Audit-Eintraege vorhanden.</div>';
  return entries.map((entry) => `
    <div class="intern-card">
      <strong>${escapeHtml(entry.action_type)}</strong>
      <p class="intern-meta">${formatDateTime(entry.created_at)}${entry.actor_name ? ` · ${escapeHtml(entry.actor_name)}` : ''}</p>
      <p class="intern-meta">${escapeHtml(entry.field_name ?? 'Datensatz')} · ${escapeHtml(entry.old_value ?? '—')} → ${escapeHtml(entry.new_value ?? '—')}</p>
      ${entry.reason ? `<p class="intern-meta">${escapeHtml(entry.reason)}</p>` : ''}
    </div>
  `).join('');
}

function renderAnalysisCard(label: string, value: number) {
  return `<article class="intern-stat"><span>${escapeHtml(label)}</span><strong>${value}</strong></article>`;
}

function renderGrouping(groups: Map<string, WindowSummary[]>) {
  if (!groups.size) return '<div class="intern-empty">Keine Daten vorhanden.</div>';
  return `<div class="intern-list">${Array.from(groups.entries()).sort(([a], [b]) => a.localeCompare(b)).map(([label, items]) => `<div class="intern-card"><strong>${escapeHtml(label)}</strong><p class="intern-meta">${items.length} Fenster · ${items.filter((item) => item.has_defect).length} mit Mangel · ${items.filter((item) => item.special_inspection_required).length} Spezialpruefungen</p></div>`).join('')}</div>`;
}

function roleBadge(role: PortalRole) {
  return `<span class="intern-badge intern-badge--info">${escapeHtml(roleLabels[role] ?? role)}</span>`;
}

function connectionBadge() {
  return navigator.onLine
    ? '<span class="intern-badge intern-badge--ok">Verbunden · Realtime aktiv</span>'
    : '<span class="intern-badge intern-badge--warn">Offline · lokale Zwischenspeicherung aktiv</span>';
}

function infoAlert(text: string) { return `<div class="intern-alert intern-alert--info">${escapeHtml(text)}</div>`; }
function successAlert(text: string) { return `<div class="intern-alert intern-alert--success">${escapeHtml(text)}</div>`; }
function warnAlert(text: string) { return `<div class="intern-alert intern-alert--warn">${escapeHtml(text)}</div>`; }
function errorAlert(text: string) { return `<div class="intern-alert intern-alert--error">${escapeHtml(text)}</div>`; }

function subscribeToWindowChanges(_context: AppContext, _handler: () => void) {
  // Realtime via Polling ersetzt Supabase-Channels; nicht implementiert da Polling genügt.
}

function subscribeToSingleRecord(_context: AppContext, _id: string, _handler: () => Promise<void>) {
  // Realtime via Polling ersetzt Supabase-Channels; nicht implementiert da Polling genügt.
}

function showInlineMessage(root: HTMLElement, html: string) {
  const existing = root.querySelector<HTMLElement>('[data-inline-message]');
  if (existing) existing.remove();
  const wrapper = document.createElement('div');
  wrapper.dataset.inlineMessage = 'true';
  wrapper.innerHTML = html;
  root.prepend(wrapper);
}

function createNotice(text: string, kind: 'warn' | 'info' | 'success' | 'error', marker = false) {
  const element = document.createElement('div');
  element.className = `intern-alert intern-alert--${kind}`;
  if (marker) element.dataset.recordRefreshNote = 'true';
  element.textContent = text;
  return element;
}

function summarizeCompletion(data: Record<string, unknown>, calculated: Record<string, unknown>) {
  return [
    `Fenster: ${String(data.window_number ?? '—')}`,
    `Standort: ${String(data.building_label ?? '')} ${String(data.section_label ?? '')} ${String(data.floor_label ?? '')} ${String(data.room_number ?? '')}`.trim(),
    `Bewertung: ${String(data.overall_rating ?? '—')}`,
    `Pruefgewicht: ${formatNumber(toNumber(calculated.appliedTestWeightKg) ?? toNumber(data.applied_test_weight_kg) ?? 0)} kg`,
    `Massnahme: ${String(data.recommended_action ?? '—')}`,
  ].join('\n');
}

function canEditRecord(role: PortalRole, record: WindowRecord) {
  if (role === 'administrator') return true;
  if (role === 'auswertung') return false;
  return record.status !== 'freigegeben' && record.status !== 'fachlich geprueft';
}

function fillPhotoCategories(select: HTMLSelectElement | null) {
  if (!select) return;
  select.innerHTML = [
    ['Gesamtansicht', 'Gesamtansicht'],
    ['Fensterkennzeichnung', 'Fensterkennzeichnung'],
    ['Verglasungskennzeichnung', 'Verglasungskennzeichnung'],
    ['Fluegellager', 'Fluegellager'],
    ['Ecklager', 'Ecklager'],
    ['Beschlagschere', 'Beschlagschere'],
    ['Beschlagkennzeichnung', 'Beschlagkennzeichnung'],
    ['Mangel', 'Mangel'],
    ['sonstiges', 'Sonstiges'],
  ].map(([value, label]) => `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`).join('');
}

function createPhotoPlaceholder(label: string) {
  const text = encodeURIComponent(label);
  return `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 640 480'%3E%3Crect width='640' height='480' fill='%230b4f7a'/%3E%3Ctext x='50%25' y='50%25' dominant-baseline='middle' text-anchor='middle' fill='white' font-family='Arial' font-size='28'%3E${text}%3C/text%3E%3C/svg%3E`;
}

function quoteCsv(value: unknown, delimiter: string) {
  const text = String(value ?? '');
  if (text.includes('"') || text.includes('\n') || text.includes(delimiter)) return `"${text.replaceAll('"', '""')}"`;
  return text;
}

function downloadBlob(fileName: string, content: string, type: string) {
  const blob = new Blob([content], { type });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  link.click();
  URL.revokeObjectURL(url);
}

function groupBy<T>(items: T[], getKey: (item: T) => string) {
  const map = new Map<string, T[]>();
  items.forEach((item) => {
    const key = getKey(item) || 'Unbekannt';
    const current = map.get(key) ?? [];
    current.push(item);
    map.set(key, current);
  });
  return map;
}

function redirectTo(path: string) {
  if (window.location.pathname === path) return;
  window.location.assign(path);
}

function readRecordIdFromPath() {
  const match = window.location.pathname.match(/\/fenster\/([^/]+)\/?$/);
  return match ? decodeURIComponent(match[1]) : null;
}

function formatDateTime(value: string | null) {
  if (!value) return '—';
  return new Intl.DateTimeFormat('de-DE', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}

function formatTime(value: string | null | undefined) {
  if (!value) return '—';
  return new Intl.DateTimeFormat('de-DE', { timeStyle: 'short' }).format(new Date(value));
}

function formatNumber(value: number) {
  return new Intl.NumberFormat('de-DE', { minimumFractionDigits: value % 1 === 0 ? 0 : 1, maximumFractionDigits: 1 }).format(value);
}

function sanitizeFileName(name: string) {
  return name.toLowerCase().replace(/[^a-z0-9._-]+/g, '-');
}

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

function stringOrNull(value: unknown) {
  const stringValue = String(value ?? '').trim();
  return stringValue ? stringValue : null;
}

function isMissing(value: unknown) {
  return value === null || value === undefined || value === '' || value === false;
}

function debounce<T extends (...args: never[]) => void>(fn: T, wait: number) {
  let timer: number | null = null;
  return (...args: Parameters<T>) => {
    if (timer) window.clearTimeout(timer);
    timer = window.setTimeout(() => fn(...args), wait);
  };
}

function escapeHtml(value: string) {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}
