import QRCode from 'qrcode';
import type { AuthChangeEvent, Session, SupabaseClient, User } from '@supabase/supabase-js';
import { calculateWindowWeights, normalizeCalculationParameters } from './calculations';
import { loadAllDrafts, loadDraft, removeDraft, saveDraft } from './offline';
import {
  exportDefinitions,
  getFieldDefinition,
  portalProject,
  requiredBeforeCompletion,
  roleLabels,
  windowFormSections,
} from './schema';
import { getSupabaseBrowserClient, hasSupabaseConfig } from './supabase';
import type {
  AuditLogEntry,
  CalculationParameterMap,
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
  supabase: SupabaseClient | null;
  session: Session | null;
  user: PortalUser | null;
  draftDirty: boolean;
}

interface WindowPayload {
  id: string;
  project_id?: string;
  record_id: string;
  inspection_number: number | null;
  window_number: string;
  room_number: string | null;
  room_label: string | null;
  building_label: string | null;
  section_label: string | null;
  floor_label: string | null;
  status: string;
  overall_rating: string | null;
  priority: string | null;
  accessibility_status: string | null;
  assigned_to: string | null;
  assigned_name: string | null;
  special_inspection_required: boolean | null;
  urgent_action_required: boolean | null;
  has_defect: boolean | null;
  danger_immediate: boolean | null;
  last_edited_at: string | null;
  updated_at: string;
  progress_percent: number | null;
  form_data: Record<string, unknown> | null;
  calculated_data: Record<string, unknown> | null;
  completed_at?: string | null;
  released_at?: string | null;
  release_reason?: string | null;
  version?: number | null;
}

const LOCK_TIMEOUT_MINUTES = 15;
const SAVE_DEBOUNCE_MS = 1200;
const SYNC_WARNING_MESSAGE = 'Es liegen noch nicht synchronisierte Aenderungen vor.';
const DEFAULT_PROJECT_ID = '11111111-1111-4111-8111-111111111111';

let authBound = false;
let lastAuthEvent: { event: AuthChangeEvent; session: Session | null } | null = null;

export async function mountInternalPortal(root: HTMLElement) {
  const route = (root.dataset.route as PortalRoute | undefined) ?? 'landing';
  const recordId = root.dataset.recordId || new URLSearchParams(window.location.search).get('id');
  const supabase = getSupabaseBrowserClient();
  const session = supabase ? (await supabase.auth.getSession()).data.session : null;
  const user = session && supabase ? await loadPortalUser(supabase, session.user) : null;
  const context: AppContext = { root, route, recordId, supabase, session, user, draftDirty: false };

  root.classList.add('intern-app');
  bindAuthListener(context);
  window.addEventListener('online', () => void syncDraftQueue(context));
  window.addEventListener('beforeunload', (event) => {
    if (!context.draftDirty) return;
    event.preventDefault();
    event.returnValue = SYNC_WARNING_MESSAGE;
  });

  await renderRoute(context);
  if (navigator.onLine) void syncDraftQueue(context);
}

function bindAuthListener(context: AppContext) {
  if (authBound || !context.supabase) return;
  authBound = true;
  context.supabase.auth.onAuthStateChange((event, session) => {
    lastAuthEvent = { event, session };
  });
  setInterval(async () => {
    if (!lastAuthEvent || !context.supabase) return;
    const { event, session } = lastAuthEvent;
    lastAuthEvent = null;
    context.session = session;
    context.user = session ? await loadPortalUser(context.supabase, session.user) : null;
    if (event === 'SIGNED_OUT' && context.route !== 'login') {
      redirectTo('/intern/login/');
      return;
    }
    await renderRoute(context);
  }, 500);
}

async function renderRoute(context: AppContext) {
  if (!hasSupabaseConfig()) {
    context.root.innerHTML = renderConfigMissing();
    return;
  }

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

function renderConfigMissing() {
  return `
    <div class="intern-card intern-login">
      <p class="sv-eyebrow">Einrichtung erforderlich</p>
      <h1>Supabase-Konfiguration fehlt</h1>
      <p>Setzen Sie <code>PUBLIC_SUPABASE_URL</code> und <code>PUBLIC_SUPABASE_ANON_KEY</code>, fuehren Sie die SQL-Migrationen aus und legen Sie Benutzerkonten an.</p>
      <div class="intern-alert intern-alert--warn">Die interne Anwendung wird erst nach abgeschlossener Supabase-Einrichtung funktionsfaehig.</div>
    </div>
  `;
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
    if (!context.supabase || !message) return;
    const email = String(new FormData(form).get('email') ?? '');
    const password = String(new FormData(form).get('password') ?? '');
    message.innerHTML = infoAlert('Anmeldung wird geprueft.');
    const { error } = await context.supabase.auth.signInWithPassword({ email, password });
    if (error) {
      message.innerHTML = errorAlert('Anmeldung fehlgeschlagen. Bitte Zugangsdaten pruefen.');
      return;
    }
    message.innerHTML = successAlert('Anmeldung erfolgreich. Weiterleitung laeuft.');
    redirectTo('/intern/fensterpruefung-bonn/');
  });
  resetButton?.addEventListener('click', async () => {
    if (!context.supabase || !message || !form) return;
    const email = String(new FormData(form).get('email') ?? '');
    if (!email) {
      message.innerHTML = warnAlert('Bitte zuerst die E-Mail-Adresse eingeben.');
      return;
    }
    const { error } = await context.supabase.auth.resetPasswordForEmail(email, {
      redirectTo: `${window.location.origin}/intern/login/`,
    });
    message.innerHTML = error ? errorAlert('Passwort-Zuruecksetzung konnte nicht gestartet werden.') : successAlert('Passwort-Zuruecksetzung ausgelöst.');
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
    if (navigator.onLine && context.supabase && canEdit && (!lock || lock.ok)) {
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
    if (navigator.onLine && context.supabase && canEdit && (!lock || lock.ok)) await saveWindow(context, record, workingCopy, true);
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
    if (context.supabase) await context.supabase.auth.signOut();
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

async function fetchWindowSummaries(context: AppContext): Promise<WindowSummary[]> {
  if (!context.supabase) return [];
  const { data, error } = await context.supabase
    .from('windows')
    .select('id, record_id, inspection_number, window_number, room_number, room_label, building_label, section_label, floor_label, status, overall_rating, priority, accessibility_status, assigned_to, assigned_name, special_inspection_required, urgent_action_required, has_defect, danger_immediate, last_edited_at, updated_at, progress_percent')
    .is('deleted_at', null)
    .order('inspection_number', { ascending: true });
  if (error || !data) return [];
  const locks = await fetchActiveLocks(context);
  return data.map((item) => {
    const lock = locks.get(item.id as string);
    return {
      id: String(item.id),
      record_id: String(item.record_id ?? item.id),
      inspection_number: toNumber(item.inspection_number),
      window_number: String(item.window_number ?? ''),
      room_number: item.room_number ? String(item.room_number) : null,
      room_label: item.room_label ? String(item.room_label) : null,
      building_label: item.building_label ? String(item.building_label) : null,
      section_label: item.section_label ? String(item.section_label) : null,
      floor_label: item.floor_label ? String(item.floor_label) : null,
      status: String(item.status ?? 'nicht begonnen') as WindowRecord['status'],
      overall_rating: item.overall_rating ? String(item.overall_rating) : null,
      priority: item.priority ? String(item.priority) : null,
      accessibility_status: item.accessibility_status ? String(item.accessibility_status) : null,
      assigned_to: item.assigned_to ? String(item.assigned_to) : null,
      assigned_name: item.assigned_name ? String(item.assigned_name) : null,
      special_inspection_required: Boolean(item.special_inspection_required),
      urgent_action_required: Boolean(item.urgent_action_required),
      has_defect: Boolean(item.has_defect),
      danger_immediate: Boolean(item.danger_immediate),
      last_edited_at: item.last_edited_at ? String(item.last_edited_at) : null,
      updated_at: String(item.updated_at),
      progress_percent: toNumber(item.progress_percent) ?? 0,
      lock_owner_id: lock?.owner_id ?? null,
      lock_owner_name: lock?.owner_name ?? null,
      lock_expires_at: lock?.expires_at ?? null,
    };
  });
}

async function fetchActiveLocks(context: AppContext) {
  const result = new Map<string, { owner_id: string | null; owner_name: string | null; expires_at: string | null }>();
  if (!context.supabase) return result;
  const { data } = await context.supabase
    .from('record_locks')
    .select('window_id, owner_id, owner_name, expires_at')
    .gt('expires_at', new Date().toISOString());
  data?.forEach((item) => {
    result.set(String(item.window_id), {
      owner_id: item.owner_id ? String(item.owner_id) : null,
      owner_name: item.owner_name ? String(item.owner_name) : null,
      expires_at: item.expires_at ? String(item.expires_at) : null,
    });
  });
  return result;
}

async function fetchWindowRecord(context: AppContext, id: string): Promise<WindowRecord | null> {
  if (!context.supabase) return null;
  const { data, error } = await context.supabase
    .from('windows')
    .select('*')
    .eq('id', id)
    .maybeSingle();
  if (error || !data) return null;
  return hydrateRecord(data as WindowPayload);
}

function hydrateRecord(data: WindowPayload): WindowRecord {
  return {
    id: data.id,
    project_id: data.project_id ?? DEFAULT_PROJECT_ID,
    record_id: data.record_id,
    inspection_number: data.inspection_number,
    window_number: data.window_number,
    room_number: data.room_number,
    room_label: data.room_label,
    building_label: data.building_label,
    section_label: data.section_label,
    floor_label: data.floor_label,
    status: data.status as WindowRecord['status'],
    overall_rating: data.overall_rating,
    priority: data.priority,
    accessibility_status: data.accessibility_status,
    assigned_to: data.assigned_to,
    assigned_name: data.assigned_name,
    special_inspection_required: Boolean(data.special_inspection_required),
    urgent_action_required: Boolean(data.urgent_action_required),
    has_defect: Boolean(data.has_defect),
    danger_immediate: Boolean(data.danger_immediate),
    last_edited_at: data.last_edited_at,
    updated_at: data.updated_at,
    progress_percent: data.progress_percent ?? 0,
    form_data: data.form_data ?? {},
    calculated_data: data.calculated_data ?? {},
    completed_at: data.completed_at ?? null,
    released_at: data.released_at ?? null,
    release_reason: data.release_reason ?? null,
    version: data.version ?? 1,
  };
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

async function fetchAuditLogs(context: AppContext, windowId: string): Promise<AuditLogEntry[]> {
  if (!context.supabase) return [];
  const { data } = await context.supabase
    .from('audit_logs')
    .select('id, action_type, field_name, old_value, new_value, reason, created_at, actor_name')
    .eq('window_id', windowId)
    .order('created_at', { ascending: false })
    .limit(20);
  return (data ?? []).map((item) => ({
    id: String(item.id),
    action_type: String(item.action_type ?? ''),
    field_name: item.field_name ? String(item.field_name) : null,
    old_value: item.old_value ? String(item.old_value) : null,
    new_value: item.new_value ? String(item.new_value) : null,
    reason: item.reason ? String(item.reason) : null,
    created_at: String(item.created_at),
    actor_name: item.actor_name ? String(item.actor_name) : null,
  }));
}

async function fetchPhotos(context: AppContext, windowId: string): Promise<PhotoItem[]> {
  if (!context.supabase) return [];
  const { data } = await context.supabase
    .from('photos')
    .select('id, window_id, category, caption, file_name, taken_at, inspector_name, storage_path')
    .eq('window_id', windowId)
    .is('deleted_at', null)
    .order('created_at', { ascending: false });
  return (data ?? []).map((item) => ({
    id: String(item.id),
    window_id: String(item.window_id),
    category: String(item.category ?? ''),
    caption: item.caption ? String(item.caption) : null,
    file_name: String(item.file_name ?? ''),
    taken_at: item.taken_at ? String(item.taken_at) : null,
    inspector_name: item.inspector_name ? String(item.inspector_name) : null,
    storage_path: String(item.storage_path ?? ''),
  }));
}

async function fetchCalculationParameters(context: AppContext): Promise<CalculationParameterMap> {
  if (!context.supabase) return normalizeCalculationParameters(null);
  const { data } = await context.supabase
    .from('calculation_parameters')
    .select('parameter_key, parameter_value')
    .is('project_id', null);
  const parameters: Partial<CalculationParameterMap> = {};
  data?.forEach((item) => {
    if (item.parameter_key === 'glassDensityKgPerM2Mm') parameters.glassDensityKgPerM2Mm = toNumber(item.parameter_value) ?? undefined;
    if (item.parameter_key === 'frameWeightFactor') parameters.frameWeightFactor = toNumber(item.parameter_value) ?? undefined;
    if (item.parameter_key === 'safetyFactor') parameters.safetyFactor = toNumber(item.parameter_value) ?? undefined;
  });
  return normalizeCalculationParameters(parameters);
}

async function loadPortalUser(supabase: SupabaseClient, user: User): Promise<PortalUser | null> {
  const { data } = await supabase
    .from('profiles')
    .select('id, email, full_name, role, is_active')
    .eq('id', user.id)
    .maybeSingle();
  if (!data) return null;
  return {
    id: user.id,
    email: user.email ?? data.email ?? '',
    profile: {
      id: String(data.id),
      email: data.email ? String(data.email) : null,
      full_name: data.full_name ? String(data.full_name) : null,
      role: String(data.role ?? 'pruefer') as PortalRole,
      is_active: Boolean(data.is_active ?? true),
    },
  };
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
  if (!context.supabase || !context.user) return;
  const summary = deriveSummaryColumns(record.id, data, context.user);
  const payload = {
    id: record.id,
    project_id: record.project_id,
    record_id: record.record_id,
    inspection_number: toNumber(data.inspection_number) ?? record.inspection_number,
    window_number: String(data.window_number ?? record.window_number ?? ''),
    room_number: stringOrNull(data.room_number),
    room_label: stringOrNull(data.room_label),
    building_label: stringOrNull(data.building_label),
    section_label: stringOrNull(data.section_label),
    floor_label: stringOrNull(data.floor_label),
    accessibility_status: stringOrNull(data.accessibility_status),
    status: String(data.status ?? record.status ?? 'in Bearbeitung'),
    overall_rating: stringOrNull(data.overall_rating),
    priority: stringOrNull(data.priority),
    assigned_to: context.user.id,
    assigned_name: context.user.profile.full_name ?? context.user.email,
    special_inspection_required: Boolean(data.special_inspection_required || data.scissor_special_inspection),
    urgent_action_required: Boolean(data.urgent_action_required),
    has_defect: hasDefect(data),
    danger_immediate: Boolean(data.danger_immediate),
    progress_percent: summary.progressPercent,
    form_data: data,
    calculated_data: record.calculated_data,
    last_edited_at: new Date().toISOString(),
    completed_at: String(data.status ?? '') === 'Pruefung abgeschlossen' ? new Date().toISOString() : record.completed_at,
    release_reason: stringOrNull(data.release_reason),
  };
  const { error } = await context.supabase.from('windows').upsert(payload).select('id').single();
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
  if (!context.supabase || !context.user) return null;
  const id = crypto.randomUUID();
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
  const { error } = await context.supabase.from('windows').insert({
    id,
    project_id: DEFAULT_PROJECT_ID,
    record_id: `BMVG-${id.slice(0, 8).toUpperCase()}`,
    inspection_number: null,
    window_number: String(formData.window_number ?? ''),
    room_number: stringOrNull(formData.room_number),
    room_label: stringOrNull(formData.room_label),
    building_label: stringOrNull(formData.building_label),
    section_label: stringOrNull(formData.section_label),
    floor_label: stringOrNull(formData.floor_label),
    status: String(formData.status ?? 'nicht begonnen'),
    assigned_to: context.user.id,
    assigned_name: context.user.profile.full_name ?? context.user.email,
    form_data: formData,
    calculated_data: {},
    progress_percent: 0,
  });
  if (error) {
    showInlineMessage(context.root, errorAlert('Neuer Datensatz konnte nicht angelegt werden.'));
    return null;
  }
  return { id };
}

async function acquireLock(context: AppContext, windowId: string): Promise<LockResult | null> {
  if (!context.supabase) return null;
  const { data, error } = await context.supabase.rpc('acquire_record_lock', {
    p_window_id: windowId,
    p_timeout_minutes: LOCK_TIMEOUT_MINUTES,
  });
  if (error) return { ok: false, message: 'Datensatzsperre konnte nicht gesetzt werden.' };
  const response = Array.isArray(data) ? data[0] : data;
  return {
    ok: Boolean(response?.ok),
    lock_id: response?.lock_id ? String(response.lock_id) : undefined,
    owner_id: response?.owner_id ? String(response.owner_id) : null,
    owner_name: response?.owner_name ? String(response.owner_name) : null,
    expires_at: response?.expires_at ? String(response.expires_at) : null,
    message: response?.message ? String(response.message) : undefined,
  };
}

function activateLockMaintenance(context: AppContext, windowId: string) {
  let lastActivity = Date.now();
  const markActivity = () => { lastActivity = Date.now(); };
  document.addEventListener('pointerdown', markActivity, { passive: true });
  document.addEventListener('keydown', markActivity);
  const interval = window.setInterval(async () => {
    if (!context.supabase) return;
    const inactiveMinutes = (Date.now() - lastActivity) / 1000 / 60;
    if (inactiveMinutes >= LOCK_TIMEOUT_MINUTES) {
      await releaseLock(context, windowId);
      window.clearInterval(interval);
      return;
    }
    await context.supabase.rpc('acquire_record_lock', { p_window_id: windowId, p_timeout_minutes: LOCK_TIMEOUT_MINUTES });
  }, 60_000);
  window.addEventListener('beforeunload', () => void releaseLock(context, windowId), { once: true });
}

async function releaseLock(context: AppContext, windowId: string) {
  if (!context.supabase) return;
  await context.supabase.rpc('release_record_lock', { p_window_id: windowId });
}

async function uploadPhotos(context: AppContext, windowId: string, files: File[], category: string, caption: string) {
  if (!context.supabase || !context.user) return [];
  for (const file of files) {
    const resized = await resizeImageIfNeeded(file);
    const fileName = `${windowId}/${crypto.randomUUID()}-${sanitizeFileName(file.name)}`;
    const { error: uploadError } = await context.supabase.storage
      .from('window-photos-private')
      .upload(fileName, resized, { contentType: resized.type || file.type, upsert: false });
    if (uploadError) continue;
    await context.supabase.from('photos').insert({
      window_id: windowId,
      category,
      caption: caption || null,
      file_name: file.name,
      storage_path: fileName,
      inspector_id: context.user.id,
      inspector_name: context.user.profile.full_name ?? context.user.email,
      taken_at: new Date().toISOString(),
    });
  }
  return fetchPhotos(context, windowId);
}

function bindPhotoDeletion(context: AppContext, windowId: string, scope?: ParentNode) {
  scope?.querySelectorAll<HTMLElement>('[data-delete-photo]').forEach((button) => {
    button.onclick = async () => {
      if (!window.confirm('Foto wirklich loeschen?')) return;
      const id = button.dataset.deletePhoto;
      const path = button.dataset.storagePath;
      if (!id || !path || !context.supabase) return;
      await context.supabase.storage.from('window-photos-private').remove([path]);
      await context.supabase.from('photos').update({ deleted_at: new Date().toISOString() }).eq('id', id);
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
  if (!context.supabase || !context.user) return;
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
  if (context.supabase && context.user) {
    await context.supabase.from('export_logs').insert({
      project_id: DEFAULT_PROJECT_ID,
      export_type: definition.title,
      exported_by: context.user.id,
      file_name: `${definition.id}.csv`,
      filter_snapshot: { exportId, rowCount: rows.length },
    });
  }
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

function subscribeToWindowChanges(context: AppContext, handler: () => void) {
  if (!context.supabase) return;
  const channel = context.supabase
    .channel(`windows-list-${context.route}`)
    .on('postgres_changes', { event: '*', schema: 'public', table: 'windows' }, handler)
    .subscribe();
  window.addEventListener('beforeunload', () => void context.supabase?.removeChannel(channel), { once: true });
}

function subscribeToSingleRecord(context: AppContext, id: string, handler: () => Promise<void>) {
  if (!context.supabase) return;
  const channel = context.supabase
    .channel(`window-record-${id}`)
    .on('postgres_changes', { event: '*', schema: 'public', table: 'windows', filter: `id=eq.${id}` }, () => { void handler(); })
    .subscribe();
  window.addEventListener('beforeunload', () => void context.supabase?.removeChannel(channel), { once: true });
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
