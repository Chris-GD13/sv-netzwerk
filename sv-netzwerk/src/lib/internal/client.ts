import QRCode from 'qrcode';
import { calculateWindowWeights } from './calculations';
import { loadAllDrafts, loadDraft, removeDraft, saveDraft } from './offline';
import { loadTemplates, saveTemplate, markTemplateUsed, deleteTemplate, updateTemplate } from './templates';
import { analyzePhoto, prefillFormFromAnalysis, type PhotoAnalysisResult } from './photo-analysis';
import {
  exportDefinitions,
  getFieldDefinition,
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
  apiListOnlineUsers,
  apiListUsers,
  apiCreateUser,
  apiUpdateUser,
  apiSetUserPassword,
  apiDeactivateUser,
  apiDeleteUserPermanent,
  loadApiUser,
  onAuthChange,
  // KI-Import
  apiAiAnalyze,
  apiAiApply,
  // Projekte
  apiListProjects,
  apiCreateProject,
  apiUpdateProject,
  apiDeleteProject,
  apiDuplicateProject,
  apiArchiveProject,
  apiCompleteProject,
  apiReopenProject,
  getProjectSlug,
  // Hierarchie
  apiListBuildings,
  apiListFloors,
  apiListRooms,
  apiListWindowsInRoom,
  apiCreateBuilding,
  apiUpdateBuilding,
  apiDeleteBuilding,
  apiDuplicateBuilding,
  apiArchiveBuilding,
  apiCreateFloor,
  apiUpdateFloor,
  apiDeleteFloor,
  apiDuplicateFloor,
  apiArchiveFloor,
  apiMoveFloor,
  apiCreateRoom,
  apiUpdateRoom,
  apiDeleteRoom,
  apiDuplicateRoom,
  apiArchiveRoom,
  apiMoveRoom,
  apiDeleteWindow,
  apiCreateWindowInRoom,
  apiMoveWindow,
  apiCompleteEntity,
  apiReopenEntity,
  // Flügel
  apiListSashes,
  apiGetSash,
  apiCreateSash,
  apiSaveSash,
  apiDeleteSash,
  apiListSashPhotos,
  apiUploadSashPhoto,
  // Demo
  apiGetDemoStatus,
  apiSeedDemoData,
} from './php-api';
import type {
  AdminUser,
  AuditLogEntry,
  Building,
  DashboardStats,
  Floor,
  LockResult,
  OnlinePortalUser,
  PhotoItem,
  PortalRoute,
  PortalRole,
  PortalUser,
  Room,
  WindowInRoom,
  WindowRecord,
  WindowSashRecord,
  WindowSashSummary,
  WindowSummary,
  WindowTemplate,
} from './types';
import type { AiAnalysisItem, AiAnalysisResult } from './php-api';

interface AppContext {
  root: HTMLElement;
  route: PortalRoute;
  recordId: string | null;
  buildingId: number | null;
  floorId: number | null;
  roomId: number | null;
  windowId: number | null;
  sashId: number | null;
  user: PortalUser | null;
  draftDirty: boolean;
}

const LOCK_TIMEOUT_MINUTES = 15;
const SAVE_DEBOUNCE_MS = 1200;
const SYNC_WARNING_MESSAGE = 'Es liegen noch nicht synchronisierte Änderungen vor.';
const ONLINE_WINDOW_MINUTES = 10;

/** Basis-URL für das aktuelle Projekt (z.B. '/intern/fensterpruefung-bonn'). */
function projectBase(): string {
  return `/intern/${getProjectSlug()}`;
}

const authListeners = new Set<(user: PortalUser | null) => void>();

export async function mountInternalPortal(root: HTMLElement) {
  if (root.dataset.internalPortalMounted === 'true') return;
  root.dataset.internalPortalMounted = 'true';
  const route = (root.dataset.route as PortalRoute | undefined) ?? 'landing';
  const searchParams = new URLSearchParams(window.location.search);
  const recordId = root.dataset.recordId || searchParams.get('id');
  const buildingId = searchParams.get('building_id') ? Number(searchParams.get('building_id')) : null;
  const floorId = searchParams.get('floor_id') ? Number(searchParams.get('floor_id')) : null;
  const roomId = searchParams.get('room_id') ? Number(searchParams.get('room_id')) : null;
  const windowId = searchParams.get('window_id') ? Number(searchParams.get('window_id')) : null;
  const sashId = searchParams.get('sash_id') ? Number(searchParams.get('sash_id')) : null;
  const user = await loadApiUser();
  const context: AppContext = { root, route, recordId, buildingId, floorId, roomId, windowId, sashId, user, draftDirty: false };
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

  // Inaktivitäts-Logout nach 10 Minuten
  const INACTIVITY_MS = 10 * 60 * 1000;
  let inactivityTimer: ReturnType<typeof setTimeout> | null = null;

  const resetInactivityTimer = () => {
    if (!context.user) return;
    if (inactivityTimer !== null) clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(async () => {
      await apiLogout();
      redirectTo('/intern/login/?reason=inactivity');
    }, INACTIVITY_MS);
  };

  const activityEvents = ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'] as const;
  activityEvents.forEach(ev =>
    document.addEventListener(ev, resetInactivityTimer, { passive: true })
  );
  disposers.push(() => {
    if (inactivityTimer !== null) clearTimeout(inactivityTimer);
    activityEvents.forEach(ev =>
      document.removeEventListener(ev, resetInactivityTimer)
    );
  });

  // Starte Timer sofort
  resetInactivityTimer();
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
    case 'projects':
      await renderProjects(context);
      break;
    case 'new-project':
      await renderNewProject(context);
      break;
    case 'dashboard':
      await renderDashboard(context);
      break;
    case 'buildings':
      await renderBuildings(context);
      break;
    case 'floors':
      await renderFloors(context);
      break;
    case 'rooms':
      await renderRooms(context);
      break;
    case 'windows':
      await renderWindowsInRoom(context);
      break;
    case 'sashes':
      await renderSashes(context);
      break;
    case 'sash':
      await renderSashInspection(context);
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
    case 'ai-import':
      await renderAiImport(context);
      break;
    case 'admin':
      await renderAdmin(context);
      break;
  }
}

function renderLanding(context: AppContext) {
   context.root.innerHTML = `
    <div class="intern-card intern-hero">
      <p class="sv-eyebrow">Geschützter Bereich</p>
      <h1>SV-Netzwerk Prüfportal</h1>
      <p>Fensterbeschlagsprüfung, Dokumentenanalyse und Prüfverwaltung</p>
      <div class="intern-actions">
        <a class="sv-button sv-button-primary" href="${context.user ? '/intern/projekte/' : '/intern/login/'}">${context.user ? 'Zum Dashboard' : 'Zur Anmeldung'}</a>
      </div>
    </div>
  `;
  if (context.user) void redirectAfterLogin();
}

async function renderNewProject(context: AppContext) {
  if (!context.user) { redirectTo('/intern/login/'); return; }
  const canCreate = ['administrator', 'projektleiter'].includes(context.user.profile.role);
  if (!canCreate) { redirectTo('/intern/projekte/'); return; }

  context.root.innerHTML = `
    <div class="intern-app">
      <div class="intern-card intern-hero">
        <p class="sv-eyebrow">SV-Netzwerk Prüfportal</p>
        <h1>Neues Projekt anlegen</h1>
        <p>Wählen Sie, wie Sie Ihr neues Prüfprojekt starten möchten.</p>
        <nav class="intern-actions">
          <a class="sv-button sv-button-secondary" href="/intern/projekte/">← Zurück</a>
        </nav>
      </div>

      <div id="new-project-wizard" class="intern-content">
        <div class="intern-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
          <button type="button" class="intern-card" id="wizard-manual" style="cursor:pointer; text-align:center; padding:2rem; border: 2px solid transparent; transition: border-color 0.2s;">
            <div style="font-size:3rem; margin-bottom:0.5rem;">📝</div>
            <h3 style="margin:0 0 0.5rem">Manuell anlegen</h3>
            <p class="intern-meta" style="margin:0">Geben Sie Projektdaten Schritt für Schritt ein. Ideal, wenn Sie die Eckdaten bereits kennen.</p>
          </button>
          <button type="button" class="intern-card" id="wizard-ai" style="cursor:pointer; text-align:center; padding:2rem; border: 2px solid transparent; transition: border-color 0.2s;">
            <div style="font-size:3rem; margin-bottom:0.5rem;">🤖</div>
            <h3 style="margin:0 0 0.5rem">Per KI aus Dokumenten</h3>
            <p class="intern-meta" style="margin:0">Laden Sie Auftragsschreiben, Fensterlisten oder Baupläne hoch – die KI legt das Projekt für Sie an.</p>
          </button>
        </div>

        <!-- Step 2: Manual form -->
        <div id="wizard-step-manual" style="display:none;">
          <div class="intern-card">
            <h2>Projekt-Stammdaten</h2>
            <p class="intern-meta">Alle Felder mit * sind Pflichtfelder. Sie können weitere Details später ergänzen.</p>
            <form id="new-project-form" class="intern-form-grid" novalidate>
              <div class="intern-field intern-field--full">
                <label for="np-title">Projektname *</label>
                <input id="np-title" type="text" required placeholder="z.B. Fensterbeschlagsprüfung Rathaus Köln" />
              </div>
              <div class="intern-field">
                <label for="np-object">Objekt / Auftraggeber</label>
                <input id="np-object" type="text" placeholder="z.B. Stadtverwaltung Köln" />
              </div>
              <div class="intern-field">
                <label for="np-address">Adresse</label>
                <input id="np-address" type="text" placeholder="z.B. Rathausplatz 1, 50667 Köln" />
              </div>
              <div class="intern-field">
                <label for="np-windows">Geplante Fensteranzahl</label>
                <input id="np-windows" type="number" min="0" placeholder="0 = noch unbekannt" />
              </div>
              <div class="intern-actions intern-field--full">
                <button type="submit" class="sv-button sv-button-primary">Projekt anlegen</button>
                <button type="button" class="sv-button sv-button-secondary" id="wizard-back-manual">← Zurück</button>
              </div>
            </form>
            <div id="np-message"></div>
          </div>
        </div>

        <!-- Step 2: AI import -->
        <div id="wizard-step-ai" style="display:none;">
          <div class="intern-card">
            <h2>Dokumente hochladen</h2>
            <p>Laden Sie ein oder mehrere Dokumente hoch. Die KI analysiert diese und erstellt automatisch ein vollständiges Projekt mit Gebäuden, Etagen, Räumen und Fenstern.</p>
            <div class="intern-alert intern-alert--info" style="margin: 1rem 0;">
              <strong>Unterstützte Formate:</strong> PDF, Bilder (JPG/PNG/TIFF), Excel, CSV, Word, E-Mail (.msg) · bis 200 MB<br>
              <strong>Beispiel-Dokumente:</strong> Auftragsschreiben, Fensterlisten, Raumlisten, Baupläne, Prüfprotokolle
            </div>
            <p class="intern-meta">Die KI überschreibt niemals vorhandene Daten. Vor der Übernahme werden alle erkannten Daten zur Prüfung angezeigt.</p>
            <div class="intern-actions">
              <a class="sv-button sv-button-primary" href="${projectBase()}/import/">📄 Zum KI-Import (bestehendes Projekt)</a>
              <button type="button" class="sv-button sv-button-secondary" id="wizard-back-ai">← Zurück</button>
            </div>
            <div class="intern-alert intern-alert--warn" style="margin-top:1rem;">
              <strong>Tipp:</strong> Legen Sie zuerst das Projekt manuell an (Name + Adresse genügt), wechseln Sie dann in den KI-Import und laden dort Ihre Dokumente hoch.
            </div>
          </div>
        </div>
      </div>
    </div>
  `;

  const manualBtn = context.root.querySelector('#wizard-manual') as HTMLElement;
  const aiBtn = context.root.querySelector('#wizard-ai') as HTMLElement;
  const stepManual = context.root.querySelector('#wizard-step-manual') as HTMLElement;
  const stepAi = context.root.querySelector('#wizard-step-ai') as HTMLElement;
  const grid = manualBtn.parentElement as HTMLElement;

  manualBtn.addEventListener('click', () => {
    grid.style.display = 'none';
    stepManual.style.display = 'block';
  });
  aiBtn.addEventListener('click', () => {
    grid.style.display = 'none';
    stepAi.style.display = 'block';
  });
  context.root.querySelector('#wizard-back-manual')?.addEventListener('click', () => {
    stepManual.style.display = 'none';
    grid.style.display = 'grid';
  });
  context.root.querySelector('#wizard-back-ai')?.addEventListener('click', () => {
    stepAi.style.display = 'none';
    grid.style.display = 'grid';
  });

  // Form submission
  const form = context.root.querySelector('#new-project-form') as HTMLFormElement;
  const msg = context.root.querySelector('#np-message') as HTMLElement;
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const title = (context.root.querySelector('#np-title') as HTMLInputElement).value.trim();
    const objectName = (context.root.querySelector('#np-object') as HTMLInputElement).value.trim();
    const address = (context.root.querySelector('#np-address') as HTMLInputElement).value.trim();
    const windows = parseInt((context.root.querySelector('#np-windows') as HTMLInputElement).value) || 0;

    if (!title) { msg.innerHTML = errorAlert('Bitte Projektname eingeben.'); return; }
    msg.innerHTML = infoAlert('Projekt wird angelegt…');

    const result = await apiCreateProject(title, objectName, address, windows);
    if (result) {
      msg.innerHTML = successAlert('Projekt erfolgreich angelegt! Weiterleitung…');
      setTimeout(() => redirectTo(`/intern/${result.project_code}/`), 1500);
    } else {
      msg.innerHTML = errorAlert('Fehler beim Anlegen des Projekts.');
    }
  });
}

async function renderProjects(context: AppContext) {
  if (!context.user) { redirectTo('/intern/login/'); return; }
  context.root.innerHTML = `<div class="intern-loading">Projekte werden geladen…</div>`;
  
  const projects = await apiListProjects();
  const isAdmin = context.user.profile.role === 'administrator' || context.user.profile.role === 'projektleiter';
  
  context.root.innerHTML = `
    <div class="intern-app">
      ${renderHeader(context, 'Projekte', 'Wählen Sie ein Projekt aus.')}
      <div class="intern-content">
        ${projects.length === 0 ? '<div class="intern-empty">Keine Projekte vorhanden.</div>' : ''}
        <div class="intern-grid">
          ${projects.map(p => {
            const isCompleted = p.title.startsWith('✅ ');
            const isArchived = p.title.startsWith('[Archiviert]');
            return `
            <div class="intern-building-card" style="position:relative">
              <a href="/intern/${escapeHtml(p.project_code)}/" style="text-decoration:none;color:inherit;display:block">
                <div class="intern-building-card__head">
                  <span class="intern-building-card__name">${escapeHtml(p.title)}</span>
                </div>
                <p class="intern-meta">${escapeHtml(p.object_name)}</p>
                <p class="intern-meta">${escapeHtml(p.address)}</p>
                <div class="intern-building-card__stats">
                  <span>${p.building_count} Gebäude</span>
                  <span>${p.window_count} / ${p.planned_window_count} Fenster</span>
                </div>
              </a>
              ${isAdmin ? `<div class="intern-card-actions" style="position:absolute;top:8px;right:8px">
                <button class="intern-action-btn" data-action="proj-menu" data-proj-id="${p.id}" title="Aktionen" aria-label="Aktionen" style="background:#fff;border:1px solid #ccc;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:1.1rem">⋮</button>
                <div class="intern-action-menu" hidden style="position:absolute;right:0;top:100%;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.15);z-index:100;min-width:180px;padding:4px 0">
                  <button data-action="edit" data-proj-id="${p.id}" data-title="${escapeAttr(p.title)}" data-obj="${escapeAttr(p.object_name)}" data-addr="${escapeAttr(p.address)}" data-wc="${p.planned_window_count}" style="display:block;width:100%;text-align:left;padding:8px 16px;border:none;background:none;cursor:pointer;font-size:0.9rem">✏️ Bearbeiten</button>
                  <button data-action="duplicate" data-proj-id="${p.id}" style="display:block;width:100%;text-align:left;padding:8px 16px;border:none;background:none;cursor:pointer;font-size:0.9rem">📋 Duplizieren</button>
                  <button data-action="archive" data-proj-id="${p.id}" style="display:block;width:100%;text-align:left;padding:8px 16px;border:none;background:none;cursor:pointer;font-size:0.9rem">📦 ${isArchived ? 'Wiederherstellen' : 'Archivieren'}</button>
                  <button data-action="${isCompleted ? 'reopen' : 'complete'}" data-proj-id="${p.id}" style="display:block;width:100%;text-align:left;padding:8px 16px;border:none;background:none;cursor:pointer;font-size:0.9rem">${isCompleted ? '🔄 Wiederaufnahme' : '✅ Abgeschlossen'}</button>
                  <hr style="margin:4px 8px;border:none;border-top:1px solid #eee">
                  <button data-action="delete" data-proj-id="${p.id}" data-title="${escapeAttr(p.title)}" style="display:block;width:100%;text-align:left;padding:8px 16px;border:none;background:none;cursor:pointer;font-size:0.9rem;color:#c62828">🗑️ Löschen</button>
                </div>
              </div>` : ''}
            </div>
          `}).join('')}
        </div>
      </div>
    </div>
  `;

  // Toggle project action menus
  context.root.querySelectorAll<HTMLButtonElement>('[data-action="proj-menu"]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const menu = btn.nextElementSibling as HTMLElement;
      // Close all other menus
      context.root.querySelectorAll<HTMLElement>('.intern-action-menu').forEach(m => {
        if (m !== menu) m.hidden = true;
      });
      menu.hidden = !menu.hidden;
    });
  });

  // Close menus on outside click
  document.addEventListener('click', () => {
    context.root.querySelectorAll<HTMLElement>('.intern-action-menu').forEach(m => m.hidden = true);
  }, { once: true });

  // Project action handlers
  context.root.querySelectorAll<HTMLButtonElement>('[data-action="edit"][data-proj-id]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const id = Number(btn.dataset.projId);
      showProjectEditDialog(context, id, btn.dataset.title ?? '', btn.dataset.obj ?? '', btn.dataset.addr ?? '', Number(btn.dataset.wc ?? '0'));
    });
  });

  context.root.querySelectorAll<HTMLButtonElement>('[data-action="duplicate"][data-proj-id]').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (!confirm('Projekt mit allen Gebäuden, Etagen und Räumen duplizieren?')) return;
      const result = await apiDuplicateProject(Number(btn.dataset.projId));
      if (result) { alert('Projekt wurde dupliziert.'); renderProjects(context); }
      else { alert('Fehler beim Duplizieren.'); }
    });
  });

  context.root.querySelectorAll<HTMLButtonElement>('[data-action="archive"][data-proj-id]').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (await apiArchiveProject(Number(btn.dataset.projId))) renderProjects(context);
      else alert('Fehler beim Archivieren.');
    });
  });

  context.root.querySelectorAll<HTMLButtonElement>('[data-action="complete"][data-proj-id]').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (await apiCompleteProject(Number(btn.dataset.projId))) renderProjects(context);
      else alert('Fehler beim Abschließen.');
    });
  });

  context.root.querySelectorAll<HTMLButtonElement>('[data-action="reopen"][data-proj-id]').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (await apiReopenProject(Number(btn.dataset.projId))) renderProjects(context);
      else alert('Fehler bei der Wiederaufnahme.');
    });
  });

  context.root.querySelectorAll<HTMLButtonElement>('[data-action="delete"][data-proj-id]').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      const title = btn.dataset.title ?? '';
      if (!confirm(`Projekt "${title}" UNWIDERRUFLICH löschen? Alle Gebäude, Fenster und Daten werden gelöscht!`)) return;
      if (!confirm('Sind Sie sicher? Dieser Vorgang kann nicht rückgängig gemacht werden!')) return;
      if (await apiDeleteProject(Number(btn.dataset.projId), true)) renderProjects(context);
      else alert('Fehler beim Löschen.');
    });
  });

  bindHeaderLogout(context);
}

function showProjectEditDialog(context: AppContext, id: number, title: string, objectName: string, address: string, windowCount: number) {
  const overlay = document.createElement('div');
  overlay.className = 'intern-modal-overlay';
  overlay.innerHTML = `
    <div class="intern-modal" style="max-width:480px">
      <h3>Projekt bearbeiten</h3>
      <form id="proj-edit-form" class="intern-form-grid" style="gap:12px">
        <div class="intern-field intern-field--full"><label>Projektname</label><input name="title" value="${escapeAttr(title)}" required /></div>
        <div class="intern-field intern-field--full"><label>Objekt</label><input name="object_name" value="${escapeAttr(objectName)}" /></div>
        <div class="intern-field intern-field--full"><label>Adresse</label><input name="address" value="${escapeAttr(address)}" /></div>
        <div class="intern-field intern-field--full"><label>Geplante Fenster</label><input name="wc" type="number" min="0" value="${windowCount}" /></div>
        <div class="intern-actions intern-field--full">
          <button class="sv-button sv-button-primary" type="submit">Speichern</button>
          <button class="sv-button sv-button-secondary" type="button" id="proj-edit-cancel">Abbrechen</button>
        </div>
      </form>
    </div>
  `;
  document.body.appendChild(overlay);
  overlay.querySelector('#proj-edit-cancel')?.addEventListener('click', () => overlay.remove());
  overlay.querySelector<HTMLFormElement>('#proj-edit-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target as HTMLFormElement);
    const ok = await apiUpdateProject(id, String(fd.get('title') ?? ''), String(fd.get('object_name') ?? ''), String(fd.get('address') ?? ''), Number(fd.get('wc') ?? 0));
    overlay.remove();
    if (ok) renderProjects(context);
    else alert('Fehler beim Speichern.');
  });
}

async function showMoveDialog(
  entityLabel: string,
  entityName: string,
  targets: Array<{ id: number; name: string }>,
  onMove: (targetId: number) => Promise<boolean>,
  onSuccess: () => void,
) {
  const options = targets.map((t) => `<option value="${t.id}">${escapeHtml(t.name)} (ID ${t.id})</option>`).join('');
  const overlay = document.createElement('div');
  overlay.className = 'intern-modal-overlay';
  overlay.innerHTML = `
    <div class="intern-modal" style="max-width:420px">
      <h3>${escapeHtml(entityLabel)} verschieben</h3>
      <p style="margin-bottom:12px;color:#5a7185">"${escapeHtml(entityName)}" verschieben nach:</p>
      <form id="move-form" class="intern-form-grid" style="gap:12px">
        <div class="intern-field intern-field--full">
          <label>Ziel auswählen</label>
          <select name="target_id" required style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:14px">
            <option value="">— Bitte wählen —</option>
            ${options}
          </select>
        </div>
        <div class="intern-actions intern-field--full">
          <button class="sv-button sv-button-primary" type="submit">Verschieben</button>
          <button class="sv-button sv-button-secondary" type="button" id="move-cancel">Abbrechen</button>
        </div>
      </form>
    </div>
  `;
  document.body.appendChild(overlay);
  overlay.querySelector('#move-cancel')?.addEventListener('click', () => overlay.remove());
  overlay.querySelector<HTMLFormElement>('#move-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target as HTMLFormElement);
    const targetId = Number(fd.get('target_id') ?? 0);
    if (!targetId) return;
    const ok = await onMove(targetId);
    overlay.remove();
    if (ok) onSuccess();
    else alert('Verschieben fehlgeschlagen.');
  });
}

async function redirectAfterLogin() {
  try {
    const projects = await apiListProjects();
    if (projects.length > 0) {
      redirectTo(`/intern/${projects[0].project_code}/`);
      return;
    }
  } catch {
    // Fallback
  }
  redirectTo('/intern/projekte/');
}

function renderLogin(context: AppContext) {
  if (context.user) {
    void redirectAfterLogin();
    return;
  }

  context.root.innerHTML = `
    <div class="intern-card intern-login">
      <p class="sv-eyebrow">Anmeldung</p>
      <h1>SV-Netzwerk Prüfportal</h1>
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

  const params = new URLSearchParams(window.location.search);
  if (params.get('reason') === 'inactivity') {
    const msg = context.root.querySelector<HTMLElement>('#intern-login-message');
    if (msg) {
      msg.innerHTML = '<div class="intern-alert intern-alert--warn">Sie wurden nach 10 Minuten Inaktivät automatisch abgemeldet.</div>';
    }
  }

  const form = context.root.querySelector<HTMLFormElement>('#intern-login-form');
  const message = context.root.querySelector<HTMLElement>('#intern-login-message');
  const resetButton = context.root.querySelector<HTMLButtonElement>('#reset-password');
  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!message) return;
    const email = String(new FormData(form).get('email') ?? '');
    const password = String(new FormData(form).get('password') ?? '');
    message.innerHTML = infoAlert('Anmeldung wird geprüft.');
    const { user, error } = await apiLogin(email, password);
    if (error || !user) {
      message.innerHTML = errorAlert('Anmeldung fehlgeschlagen. Bitte Zugangsdaten prüfen.');
      return;
    }
    message.innerHTML = successAlert('Anmeldung erfolgreich. Weiterleitung laeuft.');
    await redirectAfterLogin();
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
  const [buildings, records, onlineUsers] = await Promise.all([
    apiListBuildings(),
    fetchWindowSummaries(context),
    apiListOnlineUsers(),
  ]);
  const stats = createDashboardStats(records);

  const demoStatus = await apiGetDemoStatus();
  const isAdmin = context.user?.profile.role === 'administrator';
  const setupBanner = !demoStatus.demo_data_exists && isAdmin
    ? `<div class="intern-alert intern-alert--info">
        Noch keine Musterdaten vorhanden. Gebäude, Etagen und Räume können über die Verwaltung angelegt werden.
        <button class="sv-button sv-button-secondary" type="button" id="seed-demo-btn" style="margin-left:12px">Musterdaten anlegen</button>
       </div>`
    : '';

  context.root.innerHTML = `
    ${renderHeader(context, 'Projekt-Dashboard', 'Prüffortschritt und Übersicht.')}
    ${setupBanner}
    <div class="intern-statusbar">
      <div class="intern-card">${connectionBadge()}</div>
      <div class="intern-card">${roleBadge(context.user?.profile.role ?? 'pruefer')}<p class="intern-meta">${escapeHtml(context.user?.profile.full_name ?? context.user?.email ?? '')}</p></div>
      <div class="intern-card">${renderOnlineUsersCard(onlineUsers, context.user?.id ?? null)}</div>
      <div class="intern-card"><strong>${stats.total}</strong><p class="intern-meta">Fenster gesamt</p></div>
      <div class="intern-card"><strong>${stats.completed}</strong><p class="intern-meta">Flügel geprüft</p></div>
    </div>

    ${buildings.length > 0 ? `
    <div style="display:flex;justify-content:space-between;align-items:center;margin:24px 0 12px">
      <h2 style="margin:0">Gebäude</h2>
      <a class="sv-button sv-button-secondary" href="${projectBase()}/auswertung/">📊 Gesamtbericht</a>
    </div>
    <div class="intern-building-grid">
      ${buildings.map((b) => {
        const pct = b.progress_pct;
        const badgeClass = pct === 100 ? 'ok' : pct > 0 ? 'info' : 'warn';
        return `
          <div class="intern-building-card">
            <a href="${projectBase()}/etagen/?building_id=${b.id}" style="text-decoration:none;color:inherit;display:block;">
              <div class="intern-building-card__header">
                <strong>${escapeHtml(b.name)}</strong>
                ${b.code ? `<span class="intern-badge intern-badge--info">${escapeHtml(b.code)}</span>` : ''}
              </div>
              <div class="intern-building-stats">
                <span>${b.window_count} Fenster</span>
                <span>${b.sash_count} Flügel</span>
                ${b.sash_defect > 0 ? `<span class="intern-badge intern-badge--danger">${b.sash_defect} Mängel</span>` : ''}
              </div>
              <div class="intern-progress-bar">
                <div class="intern-progress-bar__fill intern-progress-bar__fill--${badgeClass}" style="width:${pct}%"></div>
              </div>
              <p class="intern-meta">${pct}% Flügel geprüft (${b.sash_completed}/${b.sash_count})</p>
            </a>
            <div class="intern-actions" style="margin-top:8px;padding-top:8px;border-top:1px solid #e8eef3;">
              <a class="intern-inline-button" href="${projectBase()}/etagen/?building_id=${b.id}">Etagen</a>
              <a class="intern-inline-button" href="${projectBase()}/fenster/?filter_building=${encodeURIComponent(b.name)}">Fenster</a>
              <a class="intern-inline-button" href="${projectBase()}/auswertung/?building=${encodeURIComponent(b.name)}">Auswertung</a>
            </div>
          </div>
        `;
      }).join('')}
    </div>
    ` : `<div class="intern-empty">Noch keine Gebäude angelegt. Bitte zuerst ein Gebäude über die Verwaltung anlegen.</div>`}

    <div class="intern-grid" style="margin-top:24px">
      <section class="intern-panel">
        <h2>Gesamtstatistik</h2>
        <div class="intern-stats">
          ${renderStat('Fenster gesamt', stats.total, `${projectBase()}/fenster/`)}
          ${renderStat('Nicht begonnen', stats.notStarted, `${projectBase()}/fenster/?filter_status=nicht+begonnen`)}
          ${renderStat('In Bearbeitung', stats.inProgress, `${projectBase()}/fenster/?filter_status=in+Bearbeitung`)}
          ${renderStat('Vollständig geprüft', stats.completed, `${projectBase()}/fenster/?filter_status=Pruefung+abgeschlossen`)}
          ${renderStat('Mit Mangel', stats.withDefect, `${projectBase()}/auswertung/`)}
          ${renderStat('Dringender Handlungsbedarf', stats.urgent, `${projectBase()}/auswertung/`)}
          ${renderStat('Spezialprüfung', stats.specialInspection, `${projectBase()}/auswertung/`)}
        </div>
      </section>
      <section class="intern-panel">
        <h2>Letzte Änderungen</h2>
        <div class="intern-list">
          ${stats.recentChanges.map((item) => `<a class="intern-card" href="${projectBase()}/fenster/record/?id=${encodeURIComponent(item.id)}"><strong>${escapeHtml(item.label)}</strong><p class="intern-meta">${formatDateTime(item.updatedAt)} · ${escapeHtml(item.status)}${item.user ? ` · ${escapeHtml(item.user)}` : ''}</p></a>`).join('') || '<div class="intern-empty">Noch keine Änderungen protokolliert.</div>'}
        </div>
      </section>
    </div>
  `;

  context.root.querySelector<HTMLButtonElement>('#seed-demo-btn')?.addEventListener('click', async () => {
    const btn = context.root.querySelector<HTMLButtonElement>('#seed-demo-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Wird angelegt…'; }
    const result = await apiSeedDemoData(false);
    if (result.ok) {
      await renderDashboard(context);
    } else {
      showInlineMessage(context.root, errorAlert('Musterdaten konnten nicht angelegt werden.'));
      if (btn) { btn.disabled = false; btn.textContent = 'Musterdaten anlegen'; }
    }
  });
  bindHeaderLogout(context);
}

// ── Gebäude-Übersicht ─────────────────────────────────────────────────────────

async function renderBuildings(context: AppContext) {
  const buildings = await apiListBuildings();
  const editable = canEdit(context);

  context.root.innerHTML = `
    ${renderHeader(context, 'Gebäude', 'Alle Gebäude des Projekts mit Prüffortschritt.')}
    ${editable ? `
    <div class="intern-card" style="margin-bottom:16px">
      <h2>Gebäude hinzufügen</h2>
      <form id="create-building-form" class="intern-form-grid" novalidate>
        <div class="intern-field"><label for="bname">Bezeichnung</label><input id="bname" name="name" required /></div>
        <div class="intern-field"><label for="bcode">Kürzel</label><input id="bcode" name="code" /></div>
        <div class="intern-actions intern-field--full"><button class="sv-button sv-button-primary" type="submit">Hinzufügen</button></div>
      </form>
    </div>
    ` : ''}
    <div id="building-msg"></div>
    <div class="intern-building-grid" id="building-list">
      ${buildings.map((b) => renderBuildingCard(b, editable)).join('') || '<div class="intern-empty">Noch keine Gebäude vorhanden.</div>'}
    </div>
  `;

  if (editable) {
    context.root.querySelector<HTMLFormElement>('#create-building-form')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.currentTarget as HTMLFormElement;
      const fd = new FormData(form);
      const result = await apiCreateBuilding(String(fd.get('name') ?? '').trim(), String(fd.get('code') ?? '').trim());
      if (result) {
        form.reset();
        await renderBuildings(context);
      } else {
        const msg = context.root.querySelector<HTMLElement>('#building-msg');
        if (msg) msg.innerHTML = errorAlert('Gebäude konnte nicht angelegt werden.');
      }
    });
  }

  // Aktionsmenü-Handler binden
  bindBuildingActions(context);
  bindHeaderLogout(context);
}

function renderBuildingCard(b: Building, isAdmin: boolean): string {
  const pct = b.progress_pct;
  const badgeClass = pct === 100 ? 'ok' : pct > 0 ? 'info' : 'warn';
  const isCompleted = b.name.startsWith('✅ ');
  const isArchived = b.name.startsWith('[Archiviert]');
  return `
    <div class="intern-building-card-wrapper" data-building-id="${b.id}" data-building-name="${escapeHtml(b.name)}" data-building-code="${escapeHtml(b.code ?? '')}">
      <a class="intern-building-card" href="${projectBase()}/etagen/?building_id=${b.id}">
        <div class="intern-building-card__header">
          <strong>${escapeHtml(b.name)}</strong>
          ${b.code ? `<span class="intern-badge intern-badge--info">${escapeHtml(b.code)}</span>` : ''}
        </div>
        <div class="intern-building-stats">
          <span>${b.window_count} Fenster · ${b.sash_count} Flügel</span>
          ${b.sash_defect > 0 ? `<span class="intern-badge intern-badge--danger">${b.sash_defect} Mängel</span>` : ''}
        </div>
        <div class="intern-progress-bar"><div class="intern-progress-bar__fill intern-progress-bar__fill--${badgeClass}" style="width:${pct}%"></div></div>
        <p class="intern-meta">${pct}% geprüft · ${b.sash_completed}/${b.sash_count} Flügel</p>
      </a>
      ${isAdmin ? `
      <div class="intern-card-actions">
        <button class="intern-action-btn" data-action="menu" title="Aktionen" aria-label="Aktionen">⋮</button>
        <div class="intern-action-menu" hidden>
          <button data-action="edit">✏️ Bearbeiten</button>
          <button data-action="duplicate">📋 Duplizieren</button>
          <button data-action="archive">📦 ${isArchived ? 'Wiederherstellen' : 'Archivieren'}</button>
          <button data-action="${isCompleted ? 'reopen' : 'complete'}">${isCompleted ? '🔄 Wiederaufnahme' : '✅ Abgeschlossen'}</button>
          <hr style="border:none;border-top:1px solid #e2e8f0;margin:4px 0">
          <button data-action="delete" style="color:#e53e3e">🗑️ Löschen</button>
        </div>
      </div>
      ` : ''}
    </div>
  `;
}

function bindBuildingActions(context: AppContext) {
  bindEntityActions(context, '[data-building-id]', {
    async onEdit(wrapper) {
      const id = Number(wrapper.dataset.buildingId);
      const newName = prompt('Bezeichnung:', wrapper.dataset.buildingName ?? '');
      if (newName === null || newName.trim() === '') return;
      const newCode = prompt('Kürzel:', wrapper.dataset.buildingCode ?? '') ?? '';
      if (await apiUpdateBuilding(id, newName.trim(), newCode.trim())) {
        await renderBuildings(context);
      } else {
        showMsg(context, '#building-msg', 'Gebäude konnte nicht aktualisiert werden.');
      }
    },
    async onDuplicate(wrapper) {
      const id = Number(wrapper.dataset.buildingId);
      const name = wrapper.dataset.buildingName ?? '';
      if (!confirm(`Gebäude "${name}" duplizieren?\n\nAlle Etagen und Räume werden mitkopiert.`)) return;
      if (await apiDuplicateBuilding(id)) {
        await renderBuildings(context);
      } else {
        showMsg(context, '#building-msg', 'Gebäude konnte nicht dupliziert werden.');
      }
    },
    async onArchive(wrapper) {
      const id = Number(wrapper.dataset.buildingId);
      if (await apiArchiveBuilding(id)) {
        await renderBuildings(context);
      } else {
        showMsg(context, '#building-msg', 'Gebäude konnte nicht archiviert werden.');
      }
    },
    async onComplete(wrapper) {
      const id = Number(wrapper.dataset.buildingId);
      if (await apiCompleteEntity('building', id)) {
        await renderBuildings(context);
      }
    },
    async onReopen(wrapper) {
      const id = Number(wrapper.dataset.buildingId);
      if (await apiReopenEntity('building', id)) {
        await renderBuildings(context);
      }
    },
    async onDelete(wrapper) {
      const id = Number(wrapper.dataset.buildingId);
      const name = wrapper.dataset.buildingName ?? '';
      if (!confirm(`Gebäude "${name}" wirklich löschen?\n\nAlle Etagen, Räume und Fenster werden ebenfalls gelöscht.`)) return;
      if (await apiDeleteBuilding(id)) {
        await renderBuildings(context);
      } else {
        showMsg(context, '#building-msg', 'Gebäude konnte nicht gelöscht werden.');
      }
    },
  });
}

function bindFloorActions(context: AppContext) {
  bindEntityActions(context, '[data-floor-id]', {
    async onEdit(wrapper) {
      const id = Number(wrapper.dataset.floorId);
      const newName = prompt('Bezeichnung:', wrapper.dataset.entityName ?? '');
      if (newName === null || newName.trim() === '') return;
      const newLevel = Number(prompt('Geschoss (Zahl):', wrapper.dataset.entityLevel ?? '0') ?? '0');
      if (await apiUpdateFloor(id, newName.trim(), newLevel)) {
        await renderFloors(context);
      } else {
        showMsg(context, '#floor-msg', 'Etage konnte nicht aktualisiert werden.');
      }
    },
    async onDuplicate(wrapper) {
      const id = Number(wrapper.dataset.floorId);
      const name = wrapper.dataset.entityName ?? '';
      if (!confirm(`Etage "${name}" duplizieren?\n\nAlle Räume werden mitkopiert.`)) return;
      if (await apiDuplicateFloor(id)) {
        await renderFloors(context);
      } else {
        showMsg(context, '#floor-msg', 'Etage konnte nicht dupliziert werden.');
      }
    },
    async onArchive(wrapper) {
      const id = Number(wrapper.dataset.floorId);
      if (await apiArchiveFloor(id)) {
        await renderFloors(context);
      } else {
        showMsg(context, '#floor-msg', 'Etage konnte nicht archiviert werden.');
      }
    },
    async onComplete(wrapper) {
      const id = Number(wrapper.dataset.floorId);
      if (await apiCompleteEntity('floor', id)) await renderFloors(context);
    },
    async onReopen(wrapper) {
      const id = Number(wrapper.dataset.floorId);
      if (await apiReopenEntity('floor', id)) await renderFloors(context);
    },
    async onMove(wrapper) {
      const id = Number(wrapper.dataset.floorId);
      const name = wrapper.dataset.entityName ?? '';
      const buildings = await apiListBuildings();
      await showMoveDialog('Etage', name, buildings.map((b) => ({ id: b.id, name: b.name })), (tid) => apiMoveFloor(id, tid), () => renderFloors(context));
    },
    async onDelete(wrapper) {
      const id = Number(wrapper.dataset.floorId);
      const name = wrapper.dataset.entityName ?? '';
      if (!confirm(`Etage "${name}" wirklich löschen?\n\nAlle Räume und Fenster in dieser Etage werden ebenfalls gelöscht.`)) return;
      if (await apiDeleteFloor(id)) {
        await renderFloors(context);
      } else {
        showMsg(context, '#floor-msg', 'Etage konnte nicht gelöscht werden.');
      }
    },
  });
}

function bindRoomActions(context: AppContext) {
  bindEntityActions(context, '[data-room-id]', {
    async onEdit(wrapper) {
      const id = Number(wrapper.dataset.roomId);
      const newName = prompt('Bezeichnung:', wrapper.dataset.entityName ?? '');
      if (newName === null || newName.trim() === '') return;
      const newNumber = prompt('Raumnummer:', wrapper.dataset.entityNumber ?? '') ?? '';
      if (await apiUpdateRoom(id, newName.trim(), newNumber.trim())) {
        await renderRooms(context);
      } else {
        showMsg(context, '#room-msg', 'Raum konnte nicht aktualisiert werden.');
      }
    },
    async onDuplicate(wrapper) {
      const id = Number(wrapper.dataset.roomId);
      const name = wrapper.dataset.entityName ?? '';
      if (!confirm(`Raum "${name}" duplizieren?`)) return;
      if (await apiDuplicateRoom(id)) {
        await renderRooms(context);
      } else {
        showMsg(context, '#room-msg', 'Raum konnte nicht dupliziert werden.');
      }
    },
    async onArchive(wrapper) {
      const id = Number(wrapper.dataset.roomId);
      if (await apiArchiveRoom(id)) {
        await renderRooms(context);
      } else {
        showMsg(context, '#room-msg', 'Raum konnte nicht archiviert werden.');
      }
    },
    async onComplete(wrapper) {
      const id = Number(wrapper.dataset.roomId);
      if (await apiCompleteEntity('room', id)) await renderRooms(context);
    },
    async onReopen(wrapper) {
      const id = Number(wrapper.dataset.roomId);
      if (await apiReopenEntity('room', id)) await renderRooms(context);
    },
    async onMove(wrapper) {
      const id = Number(wrapper.dataset.roomId);
      const name = wrapper.dataset.entityName ?? '';
      const buildingId = context.buildingId ?? Number(new URLSearchParams(window.location.search).get('building_id') ?? 0);
      const floors = buildingId ? await apiListFloors(buildingId) : [];
      await showMoveDialog('Raum', name, floors.map((f) => ({ id: f.id, name: f.name })), (tid) => apiMoveRoom(id, tid), () => renderRooms(context));
    },
    async onDelete(wrapper) {
      const id = Number(wrapper.dataset.roomId);
      const name = wrapper.dataset.entityName ?? '';
      if (!confirm(`Raum "${name}" wirklich löschen?\n\nAlle Fenster in diesem Raum werden ebenfalls gelöscht.`)) return;
      if (await apiDeleteRoom(id)) {
        await renderRooms(context);
      } else {
        showMsg(context, '#room-msg', 'Raum konnte nicht gelöscht werden.');
      }
    },
  });
}

function bindWindowActions(context: AppContext) {
  bindEntityActions(context, '[data-window-id]', {
    async onDelete(wrapper) {
      const id = Number(wrapper.dataset.windowId);
      const name = wrapper.dataset.entityName ?? '';
      if (!confirm(`Fenster "${name}" wirklich löschen?`)) return;
      if (await apiDeleteWindow(id)) {
        await renderWindowsInRoom(context);
      } else {
        showMsg(context, '#window-room-msg', 'Fenster konnte nicht gelöscht werden.');
      }
    },
  });
}

function showMsg(context: AppContext, selector: string, text: string) {
  const el = context.root.querySelector<HTMLElement>(selector);
  if (el) el.innerHTML = errorAlert(text);
}

function bindEntityActions(context: AppContext, wrapperSelector: string, handlers: { onEdit?: (w: HTMLElement) => void; onDelete?: (w: HTMLElement) => void; onDuplicate?: (w: HTMLElement) => void; onArchive?: (w: HTMLElement) => void; onMove?: (w: HTMLElement) => void; onComplete?: (w: HTMLElement) => void; onReopen?: (w: HTMLElement) => void }) {
  // Toggle-Menü
  context.root.querySelectorAll<HTMLElement>('.intern-card-actions [data-action="menu"]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const menu = btn.nextElementSibling as HTMLElement;
      context.root.querySelectorAll<HTMLElement>('.intern-action-menu').forEach((m) => {
        if (m !== menu) m.hidden = true;
      });
      menu.hidden = !menu.hidden;
    });
  });

  // Klick außerhalb schließt Menüs
  context.root.addEventListener('click', (e) => {
    if (!(e.target as HTMLElement).closest('.intern-card-actions')) {
      context.root.querySelectorAll<HTMLElement>('.intern-action-menu').forEach((m) => m.hidden = true);
    }
  });

  const actions: Array<{ key: string; handler?: (w: HTMLElement) => void }> = [
    { key: 'edit', handler: handlers.onEdit },
    { key: 'delete', handler: handlers.onDelete },
    { key: 'duplicate', handler: handlers.onDuplicate },
    { key: 'archive', handler: handlers.onArchive },
    { key: 'move', handler: handlers.onMove },
    { key: 'complete', handler: handlers.onComplete },
    { key: 'reopen', handler: handlers.onReopen },
  ];

  for (const { key, handler } of actions) {
    if (!handler) continue;
    context.root.querySelectorAll<HTMLElement>(`${wrapperSelector} [data-action="${key}"]`).forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const wrapper = btn.closest<HTMLElement>(wrapperSelector)!;
        handler(wrapper);
      });
    });
  }
}

// ── Etagen ────────────────────────────────────────────────────────────────────

async function renderFloors(context: AppContext) {
  const buildingId = context.buildingId ?? Number(new URLSearchParams(window.location.search).get('building_id') ?? 0);
  if (!buildingId) { context.root.innerHTML = warnAlert('Kein Gebäude ausgewählt.'); return; }

  const floors = await apiListFloors(buildingId);
  const isAdmin = context.user?.profile.role === 'administrator';

  context.root.innerHTML = `
    ${renderHeader(context, 'Etagen', 'Bitte Etage wählen.')}
    <div class="intern-breadcrumb">
      <a href="${projectBase()}/gebaeude/">Gebäude</a> › Etagen
    </div>
    ${isAdmin ? `
    <div class="intern-card" style="margin-bottom:16px">
      <h2>Etage hinzufügen</h2>
      <form id="create-floor-form" class="intern-form-grid" novalidate>
        <div class="intern-field"><label for="fname">Bezeichnung</label><input id="fname" name="name" required /></div>
        <div class="intern-field"><label for="flevel">Geschoss (Zahl)</label><input id="flevel" name="level" type="number" value="0" /></div>
        <div class="intern-actions intern-field--full"><button class="sv-button sv-button-primary" type="submit">Hinzufügen</button></div>
      </form>
    </div>
    ` : ''}
    <div id="floor-msg"></div>
    <div class="intern-list" id="floor-list">
      ${floors.map((f) => renderFloorCard(f, buildingId, isAdmin)).join('') || '<div class="intern-empty">Noch keine Etagen vorhanden.</div>'}
    </div>
  `;

  if (isAdmin) {
    context.root.querySelector<HTMLFormElement>('#create-floor-form')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.currentTarget as HTMLFormElement;
      const fd = new FormData(form);
      const result = await apiCreateFloor(buildingId, String(fd.get('name') ?? '').trim(), Number(fd.get('level') ?? 0));
      if (result) { form.reset(); await renderFloors(context); }
      else {
        const msg = context.root.querySelector<HTMLElement>('#floor-msg');
        if (msg) msg.innerHTML = errorAlert('Etage konnte nicht angelegt werden.');
      }
    });
  }
  bindFloorActions(context);
  bindHeaderLogout(context);
}

function renderFloorCard(f: Floor, buildingId: number, isAdmin: boolean): string {
  const pct = f.progress_pct;
  const badgeClass = pct === 100 ? 'ok' : pct > 0 ? 'info' : 'warn';
  const isCompleted = f.name.startsWith('✅ ');
  const isArchived = f.name.startsWith('[Archiviert]');
  return `
    <div class="intern-list-item-wrapper" data-floor-id="${f.id}" data-entity-name="${escapeHtml(f.name)}" data-entity-level="${f.level ?? 0}">
      <a class="intern-card intern-list-item" href="${projectBase()}/raeume/?floor_id=${f.id}&building_id=${buildingId}">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong>${escapeHtml(f.name)}</strong>
          <span class="intern-badge intern-badge--${badgeClass}">${pct}%</span>
        </div>
        <p class="intern-meta">${f.room_count} Räume · ${f.window_count} Fenster · ${f.sash_count} Flügel · ${f.sash_completed} geprüft</p>
        <div class="intern-progress-bar"><div class="intern-progress-bar__fill intern-progress-bar__fill--${badgeClass}" style="width:${pct}%"></div></div>
      </a>
      ${isAdmin ? `
      <div class="intern-card-actions">
        <button class="intern-action-btn" data-action="menu" title="Aktionen">⋮</button>
        <div class="intern-action-menu" hidden>
          <button data-action="edit">✏️ Bearbeiten</button>
          <button data-action="duplicate">📋 Duplizieren</button>
          <button data-action="move">↗️ Verschieben</button>
          <button data-action="archive">📦 ${isArchived ? 'Wiederherstellen' : 'Archivieren'}</button>
          <button data-action="${isCompleted ? 'reopen' : 'complete'}">${isCompleted ? '🔄 Wiederaufnahme' : '✅ Abgeschlossen'}</button>
          <hr style="border:none;border-top:1px solid #e2e8f0;margin:4px 0">
          <button data-action="delete" style="color:#e53e3e">🗑️ Löschen</button>
        </div>
      </div>
      ` : ''}
    </div>
  `;
}

// ── Räume ─────────────────────────────────────────────────────────────────────

async function renderRooms(context: AppContext) {
  const searchParams = new URLSearchParams(window.location.search);
  const floorId = context.floorId ?? Number(searchParams.get('floor_id') ?? 0);
  const buildingId = context.buildingId ?? Number(searchParams.get('building_id') ?? 0);
  if (!floorId) { context.root.innerHTML = warnAlert('Keine Etage ausgewählt.'); return; }

  const rooms = await apiListRooms(floorId);
  const isAdmin = context.user?.profile.role === 'administrator';

  context.root.innerHTML = `
    ${renderHeader(context, 'Räume', 'Bitte Raum wählen.')}
    <div class="intern-breadcrumb">
      <a href="${projectBase()}/gebaeude/">Gebäude</a> ›
      <a href="${projectBase()}/etagen/?building_id=${buildingId}">Etagen</a> ›
      Räume
    </div>
    ${isAdmin ? `
    <div class="intern-card" style="margin-bottom:16px">
      <h2>Raum hinzufügen</h2>
      <form id="create-room-form" class="intern-form-grid" novalidate>
        <div class="intern-field"><label for="rname">Bezeichnung</label><input id="rname" name="name" required /></div>
        <div class="intern-field"><label for="rnumber">Raumnummer</label><input id="rnumber" name="room_number" /></div>
        <div class="intern-actions intern-field--full"><button class="sv-button sv-button-primary" type="submit">Hinzufügen</button></div>
      </form>
    </div>
    ` : ''}
    <div id="room-msg"></div>
    <div class="intern-list" id="room-list">
      ${rooms.map((r) => renderRoomCard(r, floorId, buildingId, isAdmin)).join('') || '<div class="intern-empty">Noch keine Räume vorhanden.</div>'}
    </div>
  `;

  if (isAdmin) {
    context.root.querySelector<HTMLFormElement>('#create-room-form')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.currentTarget as HTMLFormElement;
      const fd = new FormData(form);
      const result = await apiCreateRoom(floorId, String(fd.get('name') ?? '').trim(), String(fd.get('room_number') ?? '').trim());
      if (result) { form.reset(); await renderRooms(context); }
      else {
        const msg = context.root.querySelector<HTMLElement>('#room-msg');
        if (msg) msg.innerHTML = errorAlert('Raum konnte nicht angelegt werden.');
      }
    });
  }
  bindRoomActions(context);
  bindHeaderLogout(context);
}

function renderRoomCard(r: Room, floorId: number, buildingId: number, isAdmin: boolean): string {
  const pct = r.progress_pct;
  const badgeClass = pct === 100 ? 'ok' : pct > 0 ? 'info' : 'warn';
  const isCompleted = r.name.startsWith('✅ ');
  const isArchived = r.name.startsWith('[Archiviert]');
  return `
    <div class="intern-list-item-wrapper" data-room-id="${r.id}" data-entity-name="${escapeHtml(r.name)}" data-entity-number="${escapeHtml(r.room_number ?? '')}">
      <a class="intern-card intern-list-item" href="${projectBase()}/fenster/?room_id=${r.id}&floor_id=${floorId}&building_id=${buildingId}">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong>${escapeHtml(r.room_number ? `${r.room_number} – ${r.name}` : r.name)}</strong>
          <span class="intern-badge intern-badge--${badgeClass}">${pct}%</span>
        </div>
        <p class="intern-meta">${r.window_count} Fenster · ${r.sash_count} Flügel · ${r.sash_completed} geprüft${r.sash_defect > 0 ? ` · <span style="color:#c0392b">${r.sash_defect} Mängel</span>` : ''}</p>
        <div class="intern-progress-bar"><div class="intern-progress-bar__fill intern-progress-bar__fill--${badgeClass}" style="width:${pct}%"></div></div>
      </a>
      ${isAdmin ? `
      <div class="intern-card-actions">
        <button class="intern-action-btn" data-action="menu" title="Aktionen">⋮</button>
        <div class="intern-action-menu" hidden>
          <button data-action="edit">✏️ Bearbeiten</button>
          <button data-action="duplicate">📋 Duplizieren</button>
          <button data-action="move">↗️ Verschieben</button>
          <button data-action="archive">📦 ${isArchived ? 'Wiederherstellen' : 'Archivieren'}</button>
          <button data-action="${isCompleted ? 'reopen' : 'complete'}">${isCompleted ? '🔄 Wiederaufnahme' : '✅ Abgeschlossen'}</button>
          <hr style="border:none;border-top:1px solid #e2e8f0;margin:4px 0">
          <button data-action="delete" style="color:#e53e3e">🗑️ Löschen</button>
        </div>
      </div>
      ` : ''}
    </div>
  `;
}

// ── Fenster in einem Raum ────────────────────────────────────────────────────

async function renderWindowsInRoom(context: AppContext) {
  const searchParams = new URLSearchParams(window.location.search);
  const roomId = context.roomId ?? Number(searchParams.get('room_id') ?? 0);
  const floorId = context.floorId ?? Number(searchParams.get('floor_id') ?? 0);
  const buildingId = context.buildingId ?? Number(searchParams.get('building_id') ?? 0);

  if (!roomId) {
    // Fallback: Flat-Fensterliste (legacy)
    await renderWindowsFlat(context);
    return;
  }

  const windows = await apiListWindowsInRoom(roomId);
  const isAdmin = !(['gast','auswertung'] as string[]).includes(context.user?.profile.role ?? '');
  const firstWindow = windows[0];

  context.root.innerHTML = `
    ${renderHeader(context, 'Fenster', 'Bitte Fenster wählen.')}
    <div class="intern-breadcrumb">
      <a href="${projectBase()}/gebaeude/">Gebäude</a> ›
      <a href="${projectBase()}/etagen/?building_id=${buildingId}">Etagen</a> ›
      <a href="${projectBase()}/raeume/?floor_id=${floorId}&building_id=${buildingId}">Räume</a> ›
      ${firstWindow ? escapeHtml(`${firstWindow.room_number ? firstWindow.room_number + ' – ' : ''}${firstWindow.room_name ?? 'Raum'}`) : 'Fenster'}
    </div>
    ${isAdmin ? `
    <div class="intern-actions" style="margin-bottom:16px">
      <button class="sv-button sv-button-primary" type="button" id="create-window-btn">Fenster hinzufügen</button>
    </div>
    ` : ''}
    <div id="window-room-msg"></div>
    <div class="intern-list" id="window-room-list">
      ${windows.map((w) => renderWindowInRoomCard(w, isAdmin)).join('') || '<div class="intern-empty">Noch keine Fenster in diesem Raum.</div>'}
    </div>
  `;

  context.root.querySelector<HTMLButtonElement>('#create-window-btn')?.addEventListener('click', async () => {
    const wnum = window.prompt('Fensternummer (z.B. F-001):');
    if (!wnum) return;
    const result = await apiCreateWindowInRoom(roomId, wnum.trim());
    if (result) {
      redirectTo(`${projectBase()}/fluegel/?window_id=${result.id}&room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}`);
    } else {
      const msg = context.root.querySelector<HTMLElement>('#window-room-msg');
      if (msg) msg.innerHTML = errorAlert('Fenster konnte nicht angelegt werden.');
    }
  });
  bindWindowActions(context);
  bindHeaderLogout(context);
}

function renderWindowInRoomCard(w: WindowInRoom, isAdmin: boolean): string {
  const pct = w.progress_pct;
  const badgeClass = w.sash_defect > 0 ? 'danger' : pct === 100 ? 'ok' : pct > 0 ? 'info' : 'warn';
  return `
    <div class="intern-list-item-wrapper" data-window-id="${w.id}" data-entity-name="${escapeHtml(w.window_number || w.record_id)}">
      <a class="intern-card intern-list-item" href="${projectBase()}/fluegel/?window_id=${w.id}&room_id=0&floor_id=${w.floor_id ?? 0}&building_id=${w.building_id ?? 0}">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong>${escapeHtml(w.window_number || w.record_id)}</strong>
          <span class="intern-badge intern-badge--${badgeClass}">${pct}%</span>
        </div>
        <p class="intern-meta">${w.sash_count} Flügel · ${w.sash_completed} geprüft${w.sash_defect > 0 ? ` · ${w.sash_defect} Mängel` : ''} · ${escapeHtml(w.status)}</p>
        <div class="intern-progress-bar"><div class="intern-progress-bar__fill intern-progress-bar__fill--${badgeClass}" style="width:${pct}%"></div></div>
      </a>
      ${isAdmin ? `
      <div class="intern-card-actions">
        <button class="intern-action-btn" data-action="menu" title="Aktionen">⋮</button>
        <div class="intern-action-menu" hidden>
          <button data-action="delete">🗑️ Löschen</button>
        </div>
      </div>
      ` : ''}
    </div>
  `;
}

// ── Flügelliste eines Fensters ────────────────────────────────────────────────

async function renderSashes(context: AppContext) {
  const searchParams = new URLSearchParams(window.location.search);
  const windowId = context.windowId ?? Number(searchParams.get('window_id') ?? 0);
  const roomId = context.roomId ?? Number(searchParams.get('room_id') ?? 0);
  const floorId = context.floorId ?? Number(searchParams.get('floor_id') ?? 0);
  const buildingId = context.buildingId ?? Number(searchParams.get('building_id') ?? 0);

  if (!windowId) { context.root.innerHTML = warnAlert('Kein Fenster ausgewählt.'); return; }

  const sashes = await apiListSashes(windowId);
  const canEdit = !(['gast','auswertung'] as string[]).includes(context.user?.profile.role ?? '');

  // Fenstertitel ermitteln
  const windowLabel = `Fenster #${windowId}`;
  const breadBuilding = buildingId > 0 ? `<a href="${projectBase()}/etagen/?building_id=${buildingId}">Etagen</a> › ` : '';
  const breadFloor = floorId > 0 ? `<a href="${projectBase()}/raeume/?floor_id=${floorId}&building_id=${buildingId}">Räume</a> › ` : '';
  const breadRoom = roomId > 0 ? `<a href="${projectBase()}/fenster/?room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}">Fenster</a> › ` : '';

  const overallPct = sashes.length > 0
    ? Math.round(sashes.filter((s) => ['abgeschlossen', 'freigegeben'].includes(s.status)).length / sashes.length * 100)
    : 0;

  context.root.innerHTML = `
    ${renderHeader(context, `Flügel – ${escapeHtml(windowLabel)}`, 'Bitte Flügel wählen für die Inspektion.')}
    <div class="intern-breadcrumb">
      <a href="${projectBase()}/gebaeude/">Gebäude</a> ›
      ${breadBuilding}${breadFloor}${breadRoom}
      ${escapeHtml(windowLabel)} – Flügel
    </div>
    <div class="intern-statusbar">
      <div class="intern-card"><strong>${sashes.length}</strong><p class="intern-meta">Flügel gesamt</p></div>
      <div class="intern-card"><strong>${sashes.filter((s) => ['abgeschlossen','freigegeben'].includes(s.status)).length}</strong><p class="intern-meta">Geprüft</p></div>
      <div class="intern-card"><strong>${sashes.filter((s) => s.has_defect).length}</strong><p class="intern-meta">Mit Mangel</p></div>
      <div class="intern-card">
        <div class="intern-progress-bar"><div class="intern-progress-bar__fill intern-progress-bar__fill--${overallPct === 100 ? 'ok' : 'info'}" style="width:${overallPct}%"></div></div>
        <p class="intern-meta">${overallPct}% abgeschlossen</p>
      </div>
    </div>
    ${canEdit ? `<div class="intern-actions" style="margin:12px 0"><button class="sv-button sv-button-primary" type="button" id="add-sash-btn">Flügel hinzufügen</button></div>` : ''}
    <div id="sash-msg"></div>
    <div class="intern-list" id="sash-list">
      ${sashes.map((s) => renderSashCard(s, windowId, roomId, floorId, buildingId)).join('') || '<div class="intern-empty">Noch keine Flügel vorhanden. Flügel hinzufügen.</div>'}
    </div>
  `;

  context.root.querySelector<HTMLButtonElement>('#add-sash-btn')?.addEventListener('click', async () => {
    const label = window.prompt('Flügelbezeichnung (z.B. Flügel Links):') ?? '';
    if (!label) return;
    const result = await apiCreateSash(windowId, label.trim(), 'Dreh-Kipp', '');
    if (result) {
      redirectTo(`${projectBase()}/fluegel-pruefung/?sash_id=${result.id}&window_id=${windowId}&room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}`);
    } else {
      const msg = context.root.querySelector<HTMLElement>('#sash-msg');
      if (msg) msg.innerHTML = errorAlert('Flügel konnte nicht angelegt werden.');
    }
  });
  bindHeaderLogout(context);
}

function renderSashCard(s: WindowSashSummary, windowId: number, roomId: number, floorId: number, buildingId: number): string {
  const isComplete = ['abgeschlossen', 'freigegeben'].includes(s.status);
  const badgeClass = s.has_defect ? 'danger' : isComplete ? 'ok' : s.status === 'in Bearbeitung' ? 'info' : 'warn';
  const statusLabel = sashStatusLabel(s.status);
  return `
    <a class="intern-card intern-list-item" href="${projectBase()}/fluegel-pruefung/?sash_id=${s.id}&window_id=${windowId}&room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
        <strong>${escapeHtml(s.sash_label || `Flügel ${s.sash_number}`)}</strong>
        <span class="intern-badge intern-badge--${badgeClass}">${escapeHtml(statusLabel)}</span>
      </div>
      <p class="intern-meta">
        ${s.opening_type ? escapeHtml(s.opening_type) : ''}${s.position ? ` · ${escapeHtml(s.position)}` : ''}
        ${s.overall_rating ? ` · ${escapeHtml(s.overall_rating)}` : ''}
        ${s.inspector_name ? ` · ${escapeHtml(s.inspector_name)}` : ''}
        ${s.photo_count ? ` · ${s.photo_count} Fotos` : ''}
      </p>
      <div class="intern-progress-bar">
        <div class="intern-progress-bar__fill intern-progress-bar__fill--${badgeClass}" style="width:${s.progress_percent}%"></div>
      </div>
      <p class="intern-meta">${s.progress_percent}% ausgefüllt</p>
    </a>
  `;
}

function sashStatusLabel(status: string): string {
  switch (status) {
    case 'nicht begonnen': return 'Nicht begonnen';
    case 'in Bearbeitung': return 'In Bearbeitung';
    case 'abgeschlossen': return 'Abgeschlossen';
    case 'Nachpruefung erforderlich': return 'Nachprüfung';
    case 'freigegeben': return 'Freigegeben';
    default: return status;
  }
}

// ── Flügel-Inspektion ─────────────────────────────────────────────────────────

async function renderSashInspection(context: AppContext) {
  const searchParams = new URLSearchParams(window.location.search);
  const sashId = context.sashId ?? Number(searchParams.get('sash_id') ?? 0);
  const windowId = context.windowId ?? Number(searchParams.get('window_id') ?? 0);
  const roomId = context.roomId ?? Number(searchParams.get('room_id') ?? 0);
  const floorId = context.floorId ?? Number(searchParams.get('floor_id') ?? 0);
  const buildingId = context.buildingId ?? Number(searchParams.get('building_id') ?? 0);

  if (!sashId) { context.root.innerHTML = warnAlert('Kein Flügel ausgewählt.'); return; }

  const sash = await apiGetSash(sashId);
  if (!sash) { context.root.innerHTML = errorAlert('Flügel nicht gefunden.'); return; }

  const photos = await apiListSashPhotos(sashId);
  const canEdit = !(['gast','auswertung'] as string[]).includes(context.user?.profile.role ?? '');
  const data = sash.form_data as Record<string, unknown>;

  const backUrl = `${projectBase()}/fluegel/?window_id=${windowId}&room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}`;

  context.root.innerHTML = `
    ${renderHeader(context, `${escapeHtml(sash.sash_label || `Flügel ${sash.sash_number}`)} – Prüfung`, `Fenster ${escapeHtml(sash.window_number)} · ${escapeHtml(sash.room_name ?? '')} · ${escapeHtml(sash.floor_name ?? '')} · ${escapeHtml(sash.building_name ?? '')}`)}
    <div class="intern-breadcrumb">
      <a href="${projectBase()}/gebaeude/">Gebäude</a> ›
      ${buildingId > 0 ? `<a href="${projectBase()}/etagen/?building_id=${buildingId}">Etagen</a> › ` : ''}
      ${floorId > 0 ? `<a href="${projectBase()}/raeume/?floor_id=${floorId}&building_id=${buildingId}">Räume</a> › ` : ''}
      ${roomId > 0 ? `<a href="${projectBase()}/fenster/?room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}">Fenster</a> › ` : ''}
      <a href="${backUrl}">Flügel</a> › Prüfung
    </div>
    <div class="intern-statusbar">
      <div class="intern-card">${connectionBadge()}</div>
      <div class="intern-card"><strong>${sash.progress_percent}%</strong><p class="intern-meta">Fortschritt</p></div>
      <div class="intern-card"><strong>${escapeHtml(sashStatusLabel(sash.status))}</strong><p class="intern-meta">Status</p></div>
      ${sash.has_defect ? '<div class="intern-card"><span class="intern-badge intern-badge--danger">Mangel festgestellt</span></div>' : ''}
      <div class="intern-card"><span id="save-status" class="intern-meta">Bereit</span></div>
    </div>
    <div class="intern-grid">
      <div>
        <form id="sash-form" class="intern-list" novalidate>
          ${renderSashFormSections(sash, data, !canEdit)}
        </form>
        <section class="intern-form-section">
          <h2>Fotodokumentation</h2>
          <div class="intern-upload">
            <label for="sash-photo-category">Fotokategorie</label>
            <select id="sash-photo-category">${sashPhotoCategories()}</select>
            <label for="sash-photo-caption">Bildbeschreibung</label>
            <input id="sash-photo-caption" type="text" placeholder="Optional" />
            <label for="sash-photo-files">Foto aufnehmen oder auswählen</label>
            <input id="sash-photo-files" type="file" accept="image/*" capture="environment" multiple ${!canEdit ? 'disabled' : ''} />
            <div class="intern-actions">
              <button class="sv-button sv-button-secondary" type="button" id="upload-sash-photos" ${!canEdit ? 'disabled' : ''}>Fotos hochladen</button>
            </div>
          </div>
          <div id="sash-photo-gallery" class="intern-photo-grid">${renderPhotos(photos)}</div>
        </section>
      </div>
      <aside class="intern-list">
        <section class="intern-panel">
          <h2>Navigationshilfe</h2>
          <div class="intern-list">
            <a class="intern-card" href="${backUrl}">← Zurück zur Flügelliste</a>
          </div>
        </section>
        <section class="intern-panel">
          <h2>Prüfergebnis</h2>
          <div class="intern-list">
            <div class="intern-card">
              <strong>${escapeHtml(sash.overall_rating ?? '—')}</strong>
              <p class="intern-meta">Gesamtbewertung</p>
            </div>
            <div class="intern-card">
              ${sash.has_defect ? '<span class="intern-badge intern-badge--danger">Mangel</span>' : '<span class="intern-badge intern-badge--ok">Kein Mangel</span>'}
            </div>
          </div>
        </section>
      </aside>
    </div>
    <div class="intern-sticky-actions">
      <div class="intern-progress">
        <a class="sv-button sv-button-secondary" href="${backUrl}">Zurück</a>
        <progress value="${sash.progress_percent}" max="100"></progress>
        <span>${sash.progress_percent}% Pflichtfelder</span>
      </div>
      <div class="intern-actions">
        ${canEdit ? `
          <button class="sv-button sv-button-secondary" type="button" id="sash-save-btn">Zwischenspeichern</button>
          <button class="sv-button sv-button-secondary" type="button" id="sash-print-btn">🖨 Bericht drucken</button>
          <button class="sv-button sv-button-primary" type="button" id="sash-complete-btn">Prüfung abschließen</button>
        ` : `
          <button class="sv-button sv-button-secondary" type="button" id="sash-print-btn">🖨 Bericht drucken</button>
          <span class="intern-meta">Nur lesend</span>
        `}
      </div>
    </div>
  `;

  const form = context.root.querySelector<HTMLFormElement>('#sash-form');
  const saveStatus = context.root.querySelector<HTMLElement>('#save-status');
  const workingCopy = structuredClone(data);

  const updateSaveStatus = (msg: string) => { if (saveStatus) saveStatus.textContent = msg; };

  // ── Offline-Zwischenspeicherung ──────────────────────────────────────────
  const offlineKey = `sash-draft-${sashId}`;

  const saveSashOffline = (fd: Record<string, unknown>) => {
    try { localStorage.setItem(offlineKey, JSON.stringify({ data: fd, savedAt: new Date().toISOString() })); } catch { /* ignore */ }
  };

  const clearSashOfflineDraft = () => {
    try { localStorage.removeItem(offlineKey); } catch { /* ignore */ }
  };

  // Gespeicherten Offline-Entwurf wiederherstellen (falls vorhanden und neuer als Server-Daten)
  const offlineRaw = localStorage.getItem(offlineKey);
  if (offlineRaw) {
    try {
      const offlineDraft = JSON.parse(offlineRaw) as { data: Record<string, unknown>; savedAt: string };
      const draftDate = new Date(offlineDraft.savedAt);
      const serverDate = sash.updated_at ? new Date(sash.updated_at) : new Date(0);
      if (draftDate > serverDate) {
        Object.assign(workingCopy, offlineDraft.data);
        updateSaveStatus('Offline-Entwurf wiederhergestellt');
      }
    } catch { /* ignore */ }
  }

  const persistSash = async (fd: Record<string, unknown>, showFeedback = true) => {
    if (!canEdit) return;
    if (showFeedback) updateSaveStatus('Speichern…');
    saveSashOffline(fd);
    if (!navigator.onLine) {
      updateSaveStatus('Offline gespeichert ✓');
      context.draftDirty = true;
      return;
    }
    const { error } = await apiSaveSash(sashId, fd);
    if (error) {
      updateSaveStatus('Offline gespeichert ✓');
      context.draftDirty = true;
    } else {
      clearSashOfflineDraft();
      if (showFeedback) updateSaveStatus('Gespeichert ✓');
      context.draftDirty = false;
    }
  };

  // Bei Rückkehr in den Online-Modus automatisch synchronisieren
  const handleOnline = () => void persistSash(workingCopy, true);
  window.addEventListener('online', handleOnline);

  const scheduleSave = debounce(() => void persistSash(workingCopy, false), 1500);

  form?.addEventListener('input', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement)) return;
    if (target.name) {
      workingCopy[target.name] = target instanceof HTMLInputElement && target.type === 'checkbox' ? target.checked : target.value;
    }
    context.draftDirty = true;
    updateSaveStatus('Ungespeichert…');
    scheduleSave();
  });

  // GPS-Koordinaten erfassen
  context.root.querySelector<HTMLButtonElement>('#gps-capture-btn')?.addEventListener('click', () => {
    if (!navigator.geolocation) { alert('GPS nicht verfügbar.'); return; }
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const coords = `${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)}`;
        const gpsInput = context.root.querySelector<HTMLInputElement>('#gps_position');
        if (gpsInput) { gpsInput.value = coords; workingCopy['gps_position'] = coords; scheduleSave(); }
      },
      () => alert('GPS-Position konnte nicht ermittelt werden.'),
    );
  });

  context.root.querySelector<HTMLButtonElement>('#sash-save-btn')?.addEventListener('click', () => void persistSash(workingCopy, true));

  context.root.querySelector<HTMLButtonElement>('#sash-complete-btn')?.addEventListener('click', async () => {
    if (!window.confirm(`Prüfung für „${sash.sash_label || `Flügel ${sash.sash_number}`}" wirklich abschließen?`)) return;
    workingCopy['abschlussstatus'] = 'abgeschlossen';
    workingCopy['completion_confirmed'] = true;
    const { error } = await apiSaveSash(sashId, workingCopy);
    if (error) {
      showInlineMessage(context.root, errorAlert('Abschluss fehlgeschlagen. Bitte erneut versuchen.'));
    } else {
      clearSashOfflineDraft();
      showInlineMessage(context.root, successAlert('Prüfung abgeschlossen. Weiter zum nächsten Flügel.'));
      setTimeout(() => { window.removeEventListener('online', handleOnline); redirectTo(backUrl); }, 1500);
    }
  });

  context.root.querySelector<HTMLButtonElement>('#sash-print-btn')?.addEventListener('click', () => {
    printSashReport(sash, workingCopy, photos);
  });

  context.root.querySelector<HTMLButtonElement>('#upload-sash-photos')?.addEventListener('click', async () => {
    const fileInput = context.root.querySelector<HTMLInputElement>('#sash-photo-files');
    const categorySelect = context.root.querySelector<HTMLSelectElement>('#sash-photo-category');
    const captionInput = context.root.querySelector<HTMLInputElement>('#sash-photo-caption');
    const gallery = context.root.querySelector<HTMLElement>('#sash-photo-gallery');
    if (!fileInput?.files?.length || !categorySelect || !gallery) return;

    for (const file of Array.from(fileInput.files)) {
      const resized = await resizeImageIfNeeded(file);
      await apiUploadSashPhoto(String(sash.window_id), sashId, resized, categorySelect.value, captionInput?.value ?? '');
    }

    const updatedPhotos = await apiListSashPhotos(sashId);
    gallery.innerHTML = renderPhotos(updatedPhotos);
    bindSashPhotoDeletion(sashId, gallery);
    fileInput.value = '';
    if (captionInput) captionInput.value = '';
  });

  // Per-Komponente Foto-Upload binden
  context.root.querySelectorAll<HTMLInputElement>('.intern-comp-photo-input').forEach((input) => {
    input.addEventListener('change', async () => {
      if (!input.files?.length) return;
      const component = input.dataset.component ?? 'sonstiges';
      const gallery = context.root.querySelector<HTMLElement>(`#comp-photos-${component}`);
      for (const file of Array.from(input.files)) {
        const resized = await resizeImageIfNeeded(file);
        await apiUploadSashPhoto(String(sash.window_id), sashId, resized, component, '');
      }
      const updatedPhotos = await apiListSashPhotos(sashId);
      // Alle Fotos dieser Komponente in der Mini-Galerie anzeigen
      const compPhotos = updatedPhotos.filter((p) => p.category === component);
      if (gallery) gallery.innerHTML = compPhotos.map((p) => `<img src="/intern/photos/${escapeHtml(p.storage_path)}" alt="${escapeHtml(p.caption ?? p.category)}" class="intern-comp-thumb" loading="lazy" />`).join('');
      // Globale Galerie aktualisieren
      const globalGallery = context.root.querySelector<HTMLElement>('#sash-photo-gallery');
      if (globalGallery) {
        globalGallery.innerHTML = renderPhotos(updatedPhotos);
        bindSashPhotoDeletion(sashId, globalGallery);
      }
      input.value = '';
    });
  });

  bindSashPhotoDeletion(sashId, context.root.querySelector<HTMLElement>('#sash-photo-gallery') ?? undefined);
  bindHeaderLogout(context);
}

function renderSashFormSections(sash: WindowSashRecord, data: Record<string, unknown>, disabled: boolean): string {
  const dis = disabled ? 'disabled' : '';
  const val = (key: string, fallback = '') => escapeHtml(String(data[key] ?? fallback));
  const checked = (key: string) => Boolean(data[key]) ? 'checked' : '';
  const radio = (groupName: string, value: string, label: string) => {
    const id = `rad_${groupName}_${value.replace(/[^a-zA-Z0-9]/g, '_')}`;
    const isChecked = String(data[groupName] ?? '') === value ? 'checked' : '';
    return `<label class="intern-radio"><input type="radio" id="${escapeHtml(id)}" name="${escapeHtml(groupName)}" value="${escapeHtml(value)}" ${isChecked} ${dis} /><span>${escapeHtml(label)}</span></label>`;
  };

  const componentStateOptions: [string, string][] = [
    ['OK', 'OK'],
    ['eingeschraenkt_funktionsfaehig', 'Funktionsfähig mit Einschränkungen'],
    ['defekt', 'Defekt'],
    ['fehlt', 'Fehlt'],
    ['nicht_vorhanden', 'Nicht eingebaut'],
  ];

  const componentBlock = (key: string, label: string) => `
    <div class="intern-component-block" id="comp-${escapeHtml(key)}">
      <h3 class="intern-component-title">${escapeHtml(label)}</h3>
      <div class="intern-form-grid">
        <div class="intern-field intern-field--full">
          <fieldset class="intern-radio-group">
            <legend>Zustand</legend>
            ${componentStateOptions.map(([v, l]) => radio(`${key}_status`, v, l)).join('')}
          </fieldset>
        </div>
        <div class="intern-field intern-field--full">
          <label for="${escapeHtml(key)}_bemerkung">Bemerkung / Feststellung</label>
          <textarea id="${escapeHtml(key)}_bemerkung" name="${escapeHtml(key)}_bemerkung" rows="2" ${dis}>${val(`${key}_bemerkung`)}</textarea>
        </div>
        ${!disabled ? `<div class="intern-field intern-field--full">
          <span class="intern-label-sm">Fotos zu diesem Bauteil</span>
          <div class="intern-component-photo-area">
            <label class="sv-button sv-button-ghost intern-photo-btn" style="cursor:pointer">
              📷 Foto hinzufügen
              <input type="file" class="intern-comp-photo-input" data-component="${escapeHtml(key)}" accept="image/*" capture="environment" multiple style="display:none" />
            </label>
            <div class="intern-comp-photo-gallery" id="comp-photos-${escapeHtml(key)}"></div>
          </div>
        </div>` : ''}
      </div>
    </div>
  `;

  const components: [string, string][] = [
    ['fluegellager',     'Flügellager'],
    ['scherenlager',     'Scherenlager'],
    ['ecklager',         'Ecklager'],
    ['schliessbleche',   'Schließbleche'],
    ['verriegelungen',   'Verriegelungen'],
    ['getriebe',         'Getriebe'],
    ['griff',            'Griff'],
    ['dichtungen',       'Dichtungen'],
    ['rahmen',           'Rahmen'],
    ['glas',             'Glas'],
    ['oeffnungsbegrenzer','Öffnungsbegrenzer (falls vorhanden)'],
  ];

  const suitabilityOptions: [string, string][] = [
    ['geeignet',                   'Geeignet'],
    ['geeignet_nach_nachstellung',  'Geeignet nach Nachstellung'],
    ['instandsetzung_erforderlich', 'Instandsetzung erforderlich'],
    ['austausch_empfohlen',         'Austausch empfohlen'],
  ];

  const riskOptions: [string, string][] = [
    ['niedrig', 'Niedrig'],
    ['mittel',  'Mittel'],
    ['hoch',    'Hoch'],
  ];

  const priorityOptions: [string, string][] = [
    ['keine',   'Keine'],
    ['niedrig', 'Niedrig'],
    ['mittel',  'Mittel'],
    ['hoch',    'Hoch'],
    ['sofort',  'Sofort'],
  ];

  const finalStatusOptions: [string, string][] = [
    ['entwurf',                   'Entwurf'],
    ['abgeschlossen',             'Abgeschlossen'],
    ['nachpruefung_erforderlich', 'Nachprüfung erforderlich'],
    ['freigegeben',               'Freigegeben'],
  ];

  return `
    <!-- ═══ I. Allgemein ═══════════════════════════════════════════════════ -->
    <section class="intern-form-section">
      <h2>I. Allgemein</h2>
      <div class="intern-form-grid">
        <div class="intern-field">
          <label>Gebäude</label>
          <input type="text" value="${escapeHtml(sash.building_name ?? '')}" disabled class="intern-readonly" />
        </div>
        <div class="intern-field">
          <label>Etage</label>
          <input type="text" value="${escapeHtml(sash.floor_name ?? '')}" disabled class="intern-readonly" />
        </div>
        <div class="intern-field">
          <label>Raum</label>
          <input type="text" value="${escapeHtml(`${sash.room_name ?? ''} ${sash.room_number ? `(${sash.room_number})` : ''}`.trim())}" disabled class="intern-readonly" />
        </div>
        <div class="intern-field">
          <label>Fenster-ID</label>
          <input type="text" value="${escapeHtml(sash.window_number)}" disabled class="intern-readonly" />
        </div>
        <div class="intern-field">
          <label>Flügel-ID</label>
          <input type="text" value="${escapeHtml(sash.sash_label || `Flügel ${sash.sash_number}`)}" disabled class="intern-readonly" />
        </div>
        <div class="intern-field">
          <label for="inspector_name">Prüfer *</label>
          <input id="inspector_name" name="inspector_name" type="text" value="${val('inspector_name')}" required ${dis} />
        </div>
        <div class="intern-field">
          <label for="inspection_date">Datum *</label>
          <input id="inspection_date" name="inspection_date" type="date" value="${val('inspection_date', new Date().toISOString().slice(0, 10))}" required ${dis} />
        </div>
        <div class="intern-field">
          <label for="inspection_time">Uhrzeit</label>
          <input id="inspection_time" name="inspection_time" type="time" value="${val('inspection_time')}" ${dis} />
        </div>
        <div class="intern-field">
          <label for="gps_position">GPS-Position (optional)</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input id="gps_position" name="gps_position" type="text" value="${val('gps_position')}" placeholder="z.B. 50.7374, 7.0982" ${dis} style="flex:1" />
            ${!disabled ? `<button type="button" id="gps-capture-btn" class="sv-button sv-button-ghost" title="GPS ermitteln">📍</button>` : ''}
          </div>
        </div>
        <div class="intern-field">
          <label for="qr_barcode">QR/Barcode (optional)</label>
          <input id="qr_barcode" name="qr_barcode" type="text" value="${val('qr_barcode')}" placeholder="Kennzeichnung scannen oder eingeben" ${dis} />
        </div>
      </div>
    </section>

    <!-- ═══ II. Beschlagprüfung ════════════════════════════════════════════ -->
    <section class="intern-form-section">
      <h2>II. Beschlagprüfung</h2>
      <p class="intern-meta" style="margin-bottom:16px">Für jedes Bauteil Zustand wählen, Feststellungen notieren und bei Bedarf Fotos anhängen.</p>
      ${components.map(([key, label]) => componentBlock(key, label)).join('')}
    </section>

    <!-- ═══ III. Fensterfunktion ═══════════════════════════════════════════ -->
    <section class="intern-form-section">
      <h2>III. Fensterfunktion</h2>
      <div class="intern-form-grid">
        <div class="intern-field intern-field--full">
          <fieldset class="intern-checkbox-group">
            <legend>Funktionsprüfung</legend>
            <label class="intern-checkbox"><input type="checkbox" name="fn_oeffnet_vollstaendig" ${checked('fn_oeffnet_vollstaendig')} ${dis} /><span>Öffnet vollständig</span></label>
            <label class="intern-checkbox"><input type="checkbox" name="fn_schliesst_vollstaendig" ${checked('fn_schliesst_vollstaendig')} ${dis} /><span>Schließt vollständig</span></label>
            <label class="intern-checkbox"><input type="checkbox" name="fn_verriegelung_funktioniert" ${checked('fn_verriegelung_funktioniert')} ${dis} /><span>Verriegelung funktioniert</span></label>
            <label class="intern-checkbox"><input type="checkbox" name="fn_griff_leichtgaengig" ${checked('fn_griff_leichtgaengig')} ${dis} /><span>Griffbewegung leichtgängig</span></label>
            <label class="intern-checkbox"><input type="checkbox" name="fn_kein_widerstand" ${checked('fn_kein_widerstand')} ${dis} /><span>Kein abnormaler Widerstand</span></label>
            <label class="intern-checkbox"><input type="checkbox" name="fn_kein_spiel" ${checked('fn_kein_spiel')} ${dis} /><span>Kein übermäßiges Spiel</span></label>
            <label class="intern-checkbox"><input type="checkbox" name="fn_kein_schleifen" ${checked('fn_kein_schleifen')} ${dis} /><span>Kein Schleifen</span></label>
            <label class="intern-checkbox"><input type="checkbox" name="fn_fluchtweg_moeglich" ${checked('fn_fluchtweg_moeglich')} ${dis} /><span>Rettungsweg / Fluchtweg möglich</span></label>
          </fieldset>
        </div>
        <div class="intern-field intern-field--full">
          <label for="fn_bemerkung">Bemerkungen zur Fensterfunktion</label>
          <textarea id="fn_bemerkung" name="fn_bemerkung" rows="3" ${dis}>${val('fn_bemerkung')}</textarea>
        </div>
      </div>
    </section>

    <!-- ═══ IV. Eignung ════════════════════════════════════════════════════ -->
    <section class="intern-form-section">
      <h2>IV. Eignung</h2>
      <div class="intern-form-grid">
        <div class="intern-field intern-field--full">
          <fieldset class="intern-radio-group">
            <legend>Beurteilung *</legend>
            ${suitabilityOptions.map(([v, l]) => radio('eignung_beurteilung', v, l)).join('')}
          </fieldset>
        </div>
        <div class="intern-field intern-field--full">
          <fieldset class="intern-radio-group">
            <legend>Risikostufe</legend>
            ${riskOptions.map(([v, l]) => radio('risikostufe', v, l)).join('')}
          </fieldset>
        </div>
      </div>
    </section>

    <!-- ═══ V. Maßnahmen ═══════════════════════════════════════════════════ -->
    <section class="intern-form-section">
      <h2>V. Maßnahmen</h2>
      <div class="intern-form-grid">
        <div class="intern-field intern-field--full">
          <label for="massnahme_empfehlung">Empfohlene Maßnahme *</label>
          <textarea id="massnahme_empfehlung" name="massnahme_empfehlung" rows="3" ${dis}>${val('massnahme_empfehlung')}</textarea>
        </div>
        <div class="intern-field">
          <label for="massnahme_aufwand">Geschätzter Aufwand</label>
          <input id="massnahme_aufwand" name="massnahme_aufwand" type="text" value="${val('massnahme_aufwand')}" placeholder="z.B. 1 Std. Nachstellung" ${dis} />
        </div>
        <div class="intern-field">
          <label>Priorität</label>
          <div class="intern-radio-row">
            ${priorityOptions.map(([v, l]) => radio('massnahme_prioritaet', v, l)).join('')}
          </div>
        </div>
        <div class="intern-field">
          <label for="massnahme_verantwortlich">Verantwortlich</label>
          <input id="massnahme_verantwortlich" name="massnahme_verantwortlich" type="text" value="${val('massnahme_verantwortlich')}" ${dis} />
        </div>
      </div>
    </section>

    <!-- ═══ VI. Abschlussstatus ════════════════════════════════════════════ -->
    <section class="intern-form-section">
      <h2>VI. Abschlussstatus</h2>
      <div class="intern-form-grid">
        <div class="intern-field intern-field--full">
          <fieldset class="intern-radio-group">
            <legend>Status der Prüfung</legend>
            ${finalStatusOptions.map(([v, l]) => radio('abschlussstatus', v, l)).join('')}
          </fieldset>
        </div>
      </div>
    </section>
  `;
}


function sashPhotoCategories(): string {
  return [
    ['Gesamtansicht',     'Gesamtansicht'],
    ['Fensterkennzeichnung', 'Fensterkennzeichnung'],
    ['fluegellager',      'Flügellager'],
    ['scherenlager',      'Scherenlager'],
    ['ecklager',          'Ecklager'],
    ['schliessbleche',    'Schließbleche'],
    ['verriegelungen',    'Verriegelungen'],
    ['getriebe',          'Getriebe'],
    ['griff',             'Griff'],
    ['dichtungen',        'Dichtungen'],
    ['rahmen',            'Rahmen'],
    ['glas',              'Glas'],
    ['oeffnungsbegrenzer','Öffnungsbegrenzer'],
    ['Mangel',            'Mangel / Defekt'],
    ['sonstiges',         'Sonstiges'],
  ].map(([value, label]) => `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`).join('');
}

function bindSashPhotoDeletion(sashId: number, scope?: ParentNode) {
  scope?.querySelectorAll<HTMLElement>('[data-delete-photo]').forEach((button) => {
    button.onclick = async () => {
      if (!window.confirm('Foto wirklich löschen?')) return;
      const id = button.dataset.deletePhoto;
      if (!id) return;
      await apiDeletePhoto(id);
      const gallery = button.closest('#sash-photo-gallery') as HTMLElement | null;
      if (gallery) {
        const updatedPhotos = await apiListSashPhotos(sashId);
        gallery.innerHTML = renderPhotos(updatedPhotos);
        bindSashPhotoDeletion(sashId, gallery);
      }
    };
  });
}

function bindHeaderLogout(context: AppContext) {
  context.root.querySelector<HTMLButtonElement>('#header-logout')?.addEventListener('click', async () => {
    await apiLogout();
    redirectTo('/intern/login/');
  });
}

// ── Flat-Fensterliste (Legacy-Fallback) ──────────────────────────────────────

async function renderWindowsFlat(context: AppContext) {
  const records = await fetchWindowSummaries(context);
  const filtersHtml = createFilterControls(records);
  context.root.innerHTML = `
    ${renderHeader(context, 'Fensterdatensätze', 'Suche, Filter, Datensatzsperren und Schnellzugriffe.')}
    <div class="intern-toolbar">
      <div class="intern-search">
        <label for="window-search">Suche</label>
        <input id="window-search" type="search" placeholder="Fensternummer, Raum, Gebäudeteil oder Kennzeichnung" />
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
    if (created) redirectTo(`${projectBase()}/fenster/${encodeURIComponent(created.id)}/`);
  });
  context.root.querySelector<HTMLButtonElement>('#download-qr-list')?.addEventListener('click', async () => {
    await downloadQrOverview(records);
  });
  bindWindowTableActions(context, records);
  subscribeToWindowChanges(context, () => void renderWindowsFlat(context));
  bindHeaderLogout(context);
}

function bindWindowTableActions(context: AppContext, records: WindowSummary[]) {
  context.root.querySelectorAll<HTMLElement>('[data-open-window]').forEach((el) => {
    el.onclick = (e) => {
      // Don't open if clicking on a button inside the row
      if ((e.target as HTMLElement).closest('button')) return;
      const id = el.dataset.openWindow;
      if (id) redirectTo(`${projectBase()}/fenster/${encodeURIComponent(id)}/`);
    };
  });
  context.root.querySelectorAll<HTMLElement>('[data-duplicate-window]').forEach((button) => {
    button.onclick = async (e) => {
      e.stopPropagation();
      const id = button.dataset.duplicateWindow;
      const source = records.find((record) => record.id === id);
      if (!source) return;
      const created = await createWindowRecord(context, source.id);
      if (created) redirectTo(`${projectBase()}/fenster/${encodeURIComponent(created.id)}/`);
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
          <div class="intern-card"><strong>${formatDateTime(record.updated_at)}</strong><p class="intern-meta">Letzte Änderung</p></div>
        </div>
        <section class="intern-card" id="ai-photo-section" style="margin-bottom: 1.5rem; ${!canEdit || Boolean(lock && !lock.ok) ? 'display:none' : ''}">
          <h3 style="margin: 0 0 1rem; font-size: 1rem;">📸 KI-Formular-Vorausfüllung</h3>
          <div style="display: grid; gap: 0.5rem;">
            <p style="margin: 0; font-size: 0.9rem; color: #666;">
              Fotografieren Sie ein Schild mit der Zimmernummer und die Fenster.
              Die KI erkennt automatisch: Zimmernummer, Flügelanzahl, Griffrichtung.
            </p>
            <div style="display: grid; gap: 0.5rem;">
              <label style="font-weight: 600;">1️⃣ Schild mit Zimmernummer</label>
              <button type="button" id="capture-room-sign" class="sv-button sv-button-secondary">📷 Zimmer-Schild fotografieren</button>
              <div id="room-sign-preview" style="display: none; margin-top: 0.5rem;">
                <img id="room-sign-img" style="max-width: 150px; border: 1px solid #ddd; border-radius: 0.5rem;" />
                <p id="room-sign-result" style="margin: 0.5rem 0; font-size: 0.9rem;"></p>
              </div>
            </div>
            <div style="display: grid; gap: 0.5rem;">
              <label style="font-weight: 600;">2️⃣ Fenster fotografieren</label>
              <button type="button" id="capture-window-photo" class="sv-button sv-button-secondary" disabled>📷 Fenster fotografieren</button>
              <div id="window-photo-preview" style="display: none; margin-top: 0.5rem;">
                <img id="window-photo-img" style="max-width: 150px; border: 1px solid #ddd; border-radius: 0.5rem;" />
                <p id="window-photo-result" style="margin: 0.5rem 0; font-size: 0.9rem;"></p>
              </div>
            </div>
            <button type="button" id="apply-photo-prefill" class="sv-button sv-button-secondary" disabled style="margin-top: 0.5rem;">✅ Erkannte Daten übernehmen</button>
          </div>
        </section>
          <h3 style="margin: 0 0 1rem; font-size: 1rem;">Fenster-Vorlagen</h3>
          <div style="display: grid; gap: 0.5rem;">
            <label for="template-selector">Vorlage laden:</label>
            <select id="template-selector" style="padding: 0.65rem; border: 1px solid #c6d8e3; border-radius: 0.85rem; background: #fff;">
              <option value="">— Vorlage auswählen (Hersteller, Beschlag, etc.) —</option>
            </select>
            <div style="display: flex; gap: 0.5rem;">
              <button type="button" id="load-template-btn" class="sv-button sv-button-secondary" style="flex: 1;">Laden</button>
              <button type="button" id="delete-template-btn" class="sv-button sv-button-secondary" style="flex: 1; display: none;">Löschen</button>
            </div>
          </div>
        </section>
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
            <div class="intern-card"><strong>${formatNumber(calculated.appliedTestWeightKg)} kg</strong><p class="intern-meta">Angesetztes Prüfgewicht</p></div>
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
        <a class="sv-button sv-button-secondary" href="${projectBase()}/fenster/">Zurueck</a>
        <progress value="${Math.round(record.progress_percent)}" max="100"></progress>
        <span>${Math.round(record.progress_percent)}% Pflichtfelder</span>
      </div>
      <div class="intern-actions">
        <button class="sv-button sv-button-secondary" type="button" id="save-as-template" ${!canEdit || Boolean(lock && !lock.ok) ? 'disabled' : ''}>💾 Als Vorlage speichern</button>
        <button class="sv-button sv-button-secondary" type="button" id="save-draft" ${!canEdit || Boolean(lock && !lock.ok) ? 'disabled' : ''}>Zwischenspeichern</button>
        <button class="sv-button sv-button-primary" type="button" id="complete-record" ${!canEdit || Boolean(lock && !lock.ok) ? 'disabled' : ''}>Prüfung abschließen</button>
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
    await QRCode.toCanvas(qrCanvas, `${window.location.origin}${projectBase()}/fenster/${encodeURIComponent(id)}/`, {
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
    if (!window.confirm(`Prüfung abschließen?\n\n${summary}`)) return;
    workingCopy.status = 'Pruefung abgeschlossen';
    workingCopy.completion_confirmed = true;
    await persistDraft(id, workingCopy, record.calculated_data, form);
    await saveWindow(context, record, workingCopy, true);
    await renderRecord(context);
  });

  // Template Management
  const templateSection = context.root.querySelector<HTMLElement>('#template-section');
  const templateSelector = context.root.querySelector<HTMLSelectElement>('#template-selector');
  const loadTemplateBtn = context.root.querySelector<HTMLButtonElement>('#load-template-btn');
  const deleteTemplateBtn = context.root.querySelector<HTMLButtonElement>('#delete-template-btn');
  const saveAsTemplateBtn = context.root.querySelector<HTMLButtonElement>('#save-as-template');

  if (templateSelector && loadTemplateBtn && deleteTemplateBtn && saveAsTemplateBtn) {
   // Load and populate templates
   const loadTemplateList = async () => {
     try {
       const templates = await loadTemplates(record.project_id);
       templateSelector.innerHTML = '<option value="">— Vorlage auswählen —</option>';
       templates.forEach((t) => {
         const option = document.createElement('option');
         option.value = t.id;
         option.textContent = `${t.name} (${t.usageCount}x verwendet)`;
         templateSelector.appendChild(option);
       });
     } catch (err) {
       console.error('Failed to load templates:', err);
     }
   };

   await loadTemplateList();

   templateSelector.addEventListener('change', () => {
     deleteTemplateBtn.style.display = templateSelector.value ? 'block' : 'none';
   });

   loadTemplateBtn.addEventListener('click', async () => {
     const templateId = templateSelector.value;
     if (!templateId) return;

     try {
       const templates = await loadTemplates(record.project_id);
       const template = templates.find((t) => t.id === templateId);
       if (!template) return;

       // Apply template properties to form
       Object.entries(template.properties).forEach(([key, value]) => {
         if (value !== undefined && value !== null) {
           workingCopy[key] = value;
           const input = form?.querySelector<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(`[name="${key}"]`);
           if (input) {
             if (input instanceof HTMLInputElement && input.type === 'checkbox') {
               input.checked = Boolean(value);
             } else {
               input.value = String(value);
             }
             input.dispatchEvent(new Event('input', { bubbles: true }));
           }
         }
       });

       await markTemplateUsed(templateId);
       alert(`Vorlage "${template.name}" geladen!`);
     } catch (err) {
       alert('Fehler beim Laden der Vorlage');
       console.error('Failed to load template:', err);
     }
   });

   deleteTemplateBtn.addEventListener('click', async () => {
     const templateId = templateSelector.value;
     if (!templateId || !window.confirm('Vorlage wirklich löschen?')) return;

     try {
       await deleteTemplate(templateId);
       alert('Vorlage gelöscht');
       await loadTemplateList();
       templateSelector.value = '';
       deleteTemplateBtn.style.display = 'none';
     } catch (err) {
       alert('Fehler beim Löschen der Vorlage');
       console.error('Failed to delete template:', err);
     }
   });

   saveAsTemplateBtn.addEventListener('click', async () => {
     const templateName = prompt(
       'Name für die Vorlage:',
       `${workingCopy.manufacturer || 'Hersteller'} – ${workingCopy.opening_type || 'Fensterart'}`
     );
     if (!templateName) return;

     try {
       const templateData = {
         projectId: record.project_id,
         name: templateName,
         lastUsed: null,
         usageCount: 0,
         properties: {
           manufacturer: String(workingCopy.manufacturer || ''),
           opening_type: String(workingCopy.opening_type || ''),
           frame_material: String(workingCopy.frame_material || ''),
           hinge_system: String(workingCopy.hinge_system || ''),
           hinge_manufacturer: String(workingCopy.hinge_manufacturer || ''),
           scissor_system: String(workingCopy.scissor_system || ''),
           scissor_manufacturer: String(workingCopy.scissor_manufacturer || ''),
           glass_structure: String(workingCopy.glass_structure || ''),
           glazing_type: String(workingCopy.glazing_type || ''),
           window_system: String(workingCopy.window_system || ''),
         },
       };

       await saveTemplate(templateData);
       alert(`Vorlage "${templateName}" gespeichert!`);
       await loadTemplateList();
     } catch (err) {
       alert('Fehler beim Speichern der Vorlage');
       console.error('Failed to save template:', err);
     }
   });
  }

  // AI Photo Analysis Handlers
  let roomSignPhoto: PhotoAnalysisResult | null = null;
  let windowPhoto: PhotoAnalysisResult | null = null;

  const captureRoomSignBtn = context.root.querySelector<HTMLButtonElement>('#capture-room-sign');
  const captureWindowBtn = context.root.querySelector<HTMLButtonElement>('#capture-window-photo');
  const applyPrefillBtn = context.root.querySelector<HTMLButtonElement>('#apply-photo-prefill');

  if (captureRoomSignBtn) {
   captureRoomSignBtn.addEventListener('click', async () => {
     const input = document.createElement('input');
     input.type = 'file';
     input.accept = 'image/*';
     input.capture = 'environment'; // Use rear camera on mobile

     input.addEventListener('change', async (e: any) => {
       const file = e.target.files?.[0];
       if (!file) return;

       captureRoomSignBtn.disabled = true;
       captureRoomSignBtn.textContent = '⏳ Analysiere...';

       try {
         roomSignPhoto = await analyzePhoto({
           type: 'room-sign',
           image: file,
           projectId: record.project_id,
         });

         const previewDiv = context.root.querySelector<HTMLDivElement>('#room-sign-preview');
         const imgEl = context.root.querySelector<HTMLImageElement>('#room-sign-img');
         const resultEl = context.root.querySelector<HTMLParagraphElement>('#room-sign-result');

         if (previewDiv && imgEl && resultEl) {
           const reader = new FileReader();
           reader.onload = (e: any) => {
             imgEl.src = e.target.result;
           };
           reader.readAsDataURL(file);

           previewDiv.style.display = 'block';

           if (roomSignPhoto.error) {
             resultEl.style.color = '#d32f2f';
             resultEl.textContent = `❌ ${roomSignPhoto.error}`;
           } else if (roomSignPhoto.roomNumber) {
             resultEl.style.color = '#388e3c';
             resultEl.textContent = `✅ Zimmernummer erkannt: ${roomSignPhoto.roomNumber} (${Math.round((roomSignPhoto.roomNumberConfidence || 0) * 100)}% sicher)`;
             captureWindowBtn.disabled = false;
           } else {
             resultEl.style.color = '#f57f17';
             resultEl.textContent = '⚠️ Keine Zimmernummer erkannt. Bitte erneut versuchen.';
           }

           applyPrefillBtn.disabled = !roomSignPhoto.roomNumber && !windowPhoto?.windowFluegelCount;
         }
       } catch (err) {
         alert(`Fehler bei der Bildanalyse: ${err}`);
         roomSignPhoto = { type: 'room-sign', error: String(err) };
       } finally {
         captureRoomSignBtn.disabled = false;
         captureRoomSignBtn.textContent = '📷 Zimmer-Schild fotografieren';
       }
     });

     input.click();
   });
  }

  if (captureWindowBtn) {
   captureWindowBtn.addEventListener('click', async () => {
     const input = document.createElement('input');
     input.type = 'file';
     input.accept = 'image/*';
     input.capture = 'environment';

     input.addEventListener('change', async (e: any) => {
       const file = e.target.files?.[0];
       if (!file) return;

       captureWindowBtn.disabled = true;
       captureWindowBtn.textContent = '⏳ Analysiere Fenster...';

       try {
         windowPhoto = await analyzePhoto({
           type: 'window',
           image: file,
           projectId: record.project_id,
         });

         const previewDiv = context.root.querySelector<HTMLDivElement>('#window-photo-preview');
         const imgEl = context.root.querySelector<HTMLImageElement>('#window-photo-img');
         const resultEl = context.root.querySelector<HTMLParagraphElement>('#window-photo-result');

         if (previewDiv && imgEl && resultEl) {
           const reader = new FileReader();
           reader.onload = (e: any) => {
             imgEl.src = e.target.result;
           };
           reader.readAsDataURL(file);

           previewDiv.style.display = 'block';

           if (windowPhoto.error) {
             resultEl.style.color = '#d32f2f';
             resultEl.textContent = `❌ ${windowPhoto.error}`;
           } else {
             const parts: string[] = [];
             if (windowPhoto.windowFluegelCount) {
               parts.push(`${windowPhoto.windowFluegelCount} Flügel`);
             }
             if (windowPhoto.windowSwingDirection) {
               const dirLabel = windowPhoto.windowSwingDirection === 'left' ? 'Links angeschlagen' : windowPhoto.windowSwingDirection === 'right' ? 'Rechts angeschlagen' : 'Zentrisches Fenster';
               parts.push(dirLabel);
             }

             if (parts.length > 0) {
               resultEl.style.color = '#388e3c';
               resultEl.textContent = `✅ ${parts.join(', ')} (${Math.round((windowPhoto.swingDirectionConfidence || 0.6) * 100)}% sicher)`;
             } else {
               resultEl.style.color = '#f57f17';
               resultEl.textContent = '⚠️ Fenster erkannt, aber Details unklar. Manuell nachtragen empfohlen.';
             }
           }

           applyPrefillBtn.disabled = !roomSignPhoto?.roomNumber && !windowPhoto?.windowFluegelCount;
         }
       } catch (err) {
         alert(`Fehler bei der Fensteranalyse: ${err}`);
         windowPhoto = { type: 'window', error: String(err) };
       } finally {
         captureWindowBtn.disabled = false;
         captureWindowBtn.textContent = '📷 Fenster fotografieren';
       }
     });

     input.click();
   });
  }

  if (applyPrefillBtn) {
   applyPrefillBtn.addEventListener('click', () => {
     if (roomSignPhoto && !roomSignPhoto.error) {
       prefillFormFromAnalysis(roomSignPhoto, workingCopy, form!);
     }
     if (windowPhoto && !windowPhoto.error) {
       prefillFormFromAnalysis(windowPhoto, workingCopy, form!);
     }

     alert('✅ Erkannte Daten ins Formular übernommen!');
     applyPrefillBtn.disabled = true;
   });
  }

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
  bindHeaderLogout(context);
  subscribeToSingleRecord(context, id, async () => {
    const note = context.root.querySelector<HTMLElement>('[data-record-refresh-note]');
    if (note) note.remove();
    context.root.prepend(createNotice('Der Datensatz wurde zwischenzeitlich geändert. Bitte prüfen und neu laden.', 'warn', true));
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
    ${renderHeader(context, 'Auswertung', 'Interne Übersichten für Status, Eignung und Prioritäten. Klicken Sie auf eine Kachel oder Gruppierung für Details.')}
    <div class="intern-analysis-grid">
      ${renderAnalysisCard('Geprüfte Fenster', records.filter((record) => record.status === 'Pruefung abgeschlossen' || record.status === 'freigegeben').length, 'geprueft')}
      ${renderAnalysisCard('Ungeprüfte Fenster', records.filter((record) => record.status === 'nicht begonnen').length, 'ungeprueft')}
      ${renderAnalysisCard('Nicht zugängliche Fenster', records.filter((record) => record.accessibility_status === 'nicht zugaenglich').length, 'nicht_zugaenglich')}
      ${renderAnalysisCard('Geeignete Beschläge', records.filter((record) => record.overall_rating === 'ohne festgestellten Handlungsbedarf').length, 'geeignet')}
      ${renderAnalysisCard('Nicht geeignete Beschläge', records.filter((record) => record.has_defect).length, 'mangel')}
      ${renderAnalysisCard('Spezialprüfungen', records.filter((record) => record.special_inspection_required).length, 'spezial')}
      ${renderAnalysisCard('Dringende Sicherungsmaßnahmen', records.filter((record) => record.urgent_action_required || record.danger_immediate).length, 'dringend')}
    </div>
    <div class="intern-grid">
      <section class="intern-panel"><h2>Ergebnisse je Gebäude</h2>${renderGrouping(groupings, 'building')}</section>
      <section class="intern-panel"><h2>Ergebnisse je Etage</h2>${renderGrouping(byFloor, 'floor')}</section>
      <section class="intern-panel"><h2>Ergebnisse je Prüfer</h2>${renderGrouping(byInspector, 'inspector')}</section>
      <section class="intern-panel"><h2>Ergebnisse je Fenstersystem</h2>${renderGrouping(bySystem, 'system')}</section>
    </div>
    <div id="analysis-detail" style="display:none; margin-top:1.5rem;">
      <div class="intern-card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <h2 id="detail-title">Details</h2>
          <button class="sv-button sv-button-secondary" id="detail-close">✕ Schließen</button>
        </div>
        <div id="detail-content" style="margin-top:1rem;overflow-x:auto;"></div>
      </div>
    </div>
  `;

  const detailPanel = context.root.querySelector<HTMLElement>('#analysis-detail')!;
  const detailTitle = context.root.querySelector<HTMLElement>('#detail-title')!;
  const detailContent = context.root.querySelector<HTMLElement>('#detail-content')!;

  function showDetail(title: string, items: WindowSummary[]) {
    detailTitle.textContent = title + ` (${items.length})`;
    detailContent.innerHTML = items.length === 0
      ? '<p class="intern-meta">Keine Ergebnisse.</p>'
      : `<table class="intern-table"><thead><tr><th>Fenster</th><th>Gebäude</th><th>Etage</th><th>Raum</th><th>Status</th><th>Bewertung</th></tr></thead><tbody>${items.map(r => `<tr style="cursor:pointer" data-window-id="${r.id}"><td>${escapeHtml(r.window_number || r.record_id)}</td><td>${escapeHtml(r.building_label || '-')}</td><td>${escapeHtml(r.floor_label || '-')}</td><td>${escapeHtml(r.room_number || '-')}</td><td>${escapeHtml(r.status)}</td><td>${escapeHtml(r.overall_rating || '-')}</td></tr>`).join('')}</tbody></table>`;
    detailPanel.style.display = 'block';
    detailPanel.scrollIntoView({ behavior: 'smooth' });
    // Click on row → open window record
    detailContent.querySelectorAll<HTMLElement>('[data-window-id]').forEach(row => {
      row.onclick = () => { window.location.href = projectBase() + '/fenster/?id=' + row.dataset.windowId; };
    });
  }

  // Card click handlers
  const filterMap: Record<string, (r: WindowSummary) => boolean> = {
    'geprueft': r => r.status === 'Pruefung abgeschlossen' || r.status === 'freigegeben',
    'ungeprueft': r => r.status === 'nicht begonnen',
    'nicht_zugaenglich': r => r.accessibility_status === 'nicht zugaenglich',
    'geeignet': r => r.overall_rating === 'ohne festgestellten Handlungsbedarf',
    'mangel': r => Boolean(r.has_defect),
    'spezial': r => Boolean(r.special_inspection_required),
    'dringend': r => Boolean(r.urgent_action_required || r.danger_immediate),
  };
  const filterLabels: Record<string, string> = {
    'geprueft': 'Geprüfte Fenster',
    'ungeprueft': 'Ungeprüfte Fenster',
    'nicht_zugaenglich': 'Nicht zugängliche Fenster',
    'geeignet': 'Geeignete Beschläge',
    'mangel': 'Nicht geeignete Beschläge / Mängel',
    'spezial': 'Spezialprüfungen erforderlich',
    'dringend': 'Dringende Sicherungsmaßnahmen',
  };

  context.root.querySelectorAll<HTMLElement>('[data-filter]').forEach(card => {
    card.onclick = () => {
      const key = card.dataset.filter!;
      const fn = filterMap[key];
      if (fn) showDetail(filterLabels[key] || key, records.filter(fn));
    };
  });

  // Group click handlers
  context.root.querySelectorAll<HTMLElement>('[data-group-type]').forEach(card => {
    card.onclick = () => {
      const type = card.dataset.groupType!;
      const value = card.dataset.groupValue!;
      let filtered: WindowSummary[] = [];
      if (type === 'building') filtered = records.filter(r => (r.building_label || 'Unbekannt') === value);
      else if (type === 'floor') filtered = records.filter(r => (r.floor_label || 'Unbekannt') === value);
      else if (type === 'inspector') filtered = records.filter(r => (r.assigned_name || 'Nicht zugewiesen') === value);
      else if (type === 'system') filtered = records.filter(r => String((r as unknown as { form_data?: Record<string, unknown> }).form_data?.window_system ?? 'Nicht erfasst') === value);
      showDetail(value, filtered);
    };
  });

  context.root.querySelector('#detail-close')?.addEventListener('click', () => {
    detailPanel.style.display = 'none';
  });

  bindHeaderLogout(context);
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
  bindHeaderLogout(context);
}

// ── KI-Dokumentenimport ──────────────────────────────────────────────────────

async function renderAiImport(context: AppContext) {
  const role = context.user?.profile.role ?? 'gast';
  if (!['administrator', 'pruefer'].includes(role)) {
    context.root.innerHTML = errorAlert('KI-Import ist nur für Administratoren und Prüfer verfügbar.');
    return;
  }

  context.root.innerHTML = `
    ${renderHeader(context, 'KI-Dokumentenimport', 'Laden Sie Baupläne, Fensterlisten oder Raumlisten hoch. Die KI erkennt und erfasst die Daten automatisch.')}
    <div class="intern-card" style="margin-bottom:16px">
      <div class="intern-ai-upload" id="ai-drop-zone">
        <div class="intern-ai-upload__icon">📄🤖</div>
        <p><strong>Datei hierher ziehen</strong> oder klicken zum Auswählen</p>
        <p class="intern-meta">Bilder · PDF · CSV · Excel · Word · E-Mail (.msg) · max. 200 MB</p>
        <input type="file" id="ai-file-input" accept="image/*,.pdf,.csv,.xlsx,.xls,.docx,.doc,.tiff,.tif,.msg" hidden />
      </div>
      <div id="ai-status" style="margin-top:12px"></div>
    </div>
    <div id="ai-results" style="display:none">
      <div class="intern-card">
        <h2 id="ai-doc-title">Analyseergebnis</h2>
        <p id="ai-doc-summary" class="intern-meta"></p>
        <div id="ai-items-list" style="margin-top:12px"></div>
        <div class="intern-actions" style="margin-top:16px">
          <button class="sv-button sv-button-primary" id="ai-apply-btn" disabled>Ausgewählte übernehmen</button>
          <button class="sv-button sv-button-secondary" id="ai-cancel-btn">Abbrechen</button>
        </div>
        <div id="ai-apply-result" style="margin-top:12px"></div>
      </div>
    </div>
  `;

  const dropZone = context.root.querySelector<HTMLElement>('#ai-drop-zone')!;
  const fileInput = context.root.querySelector<HTMLInputElement>('#ai-file-input')!;
  const statusEl = context.root.querySelector<HTMLElement>('#ai-status')!;
  const resultsEl = context.root.querySelector<HTMLElement>('#ai-results')!;

  dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('intern-ai-upload--hover'); });
  dropZone.addEventListener('dragleave', () => { dropZone.classList.remove('intern-ai-upload--hover'); });
  dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('intern-ai-upload--hover');
    const file = e.dataTransfer?.files[0];
    if (file) processFile(file);
  });

  dropZone.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', () => {
    const file = fileInput.files?.[0];
    if (file) processFile(file);
  });

  async function processFile(file: File) {
    statusEl.innerHTML = `<div class="intern-alert intern-alert--info">⏳ KI analysiert „${escapeHtml(file.name)}"… Bitte warten (bis zu 30 Sek.).</div>`;
    resultsEl.style.display = 'none';

    const result = await apiAiAnalyze(file);
    if (!result) {
      statusEl.innerHTML = errorAlert('KI-Analyse fehlgeschlagen. Bitte erneut versuchen oder ein anderes Dateiformat wählen.');
      return;
    }

    statusEl.innerHTML = successAlert(`Dokument analysiert: ${escapeHtml(result.analysis.summary)}`);
    renderAnalysisResults(result.analysis);
  }

  function renderAnalysisResults(analysis: AiAnalysisResult) {
    const docTitle = context.root.querySelector<HTMLElement>('#ai-doc-title')!;
    const docSummary = context.root.querySelector<HTMLElement>('#ai-doc-summary')!;
    const itemsList = context.root.querySelector<HTMLElement>('#ai-items-list')!;
    const applyBtn = context.root.querySelector<HTMLButtonElement>('#ai-apply-btn')!;

    const typeLabels: Record<string, string> = { bauplan: 'Bauplan', fensterliste: 'Fensterliste', raumliste: 'Raumliste', pruefbericht: 'Prüfbericht', herstellerdaten: 'Herstellerdaten', sonstiges: 'Dokument' };
    docTitle.textContent = typeLabels[analysis.document_type] || 'Analyseergebnis';
    docSummary.textContent = analysis.summary;

    const typeName: Record<string, string> = { building: 'Gebäude', floor: 'Etage', room: 'Raum', window: 'Fenster' };

    // Items nach Status gruppieren
    const newItems = analysis.items.filter((i) => i.status === 'new');
    const updateItems = analysis.items.filter((i) => i.status === 'update');
    const conflictItems = analysis.items.filter((i) => i.status === 'conflict');
    const existingItems = analysis.items.filter((i) => i.status === 'exists');
    const actionableItems = [...newItems, ...updateItems];

    itemsList.innerHTML = `
      ${newItems.length > 0 ? `
        <h3 style="margin-bottom:8px">📥 Neue Datensätze (${newItems.length})</h3>
        ${newItems.map((item, i) => `
          <label class="intern-ai-item intern-ai-item--new">
            <input type="checkbox" data-idx="${i}" data-group="new" checked />
            <span class="intern-badge intern-badge--ok">${typeName[item.type] || item.type}</span>
            <span>${escapeHtml(itemLabel(item))}</span>
            <span class="intern-meta" style="margin-left:auto">${Math.round(item.confidence * 100)}%</span>
          </label>
        `).join('')}
      ` : ''}
      ${updateItems.length > 0 ? `
        <h3 style="margin:12px 0 8px">🔄 Ergänzungen (${updateItems.length})</h3>
        ${updateItems.map((item, i) => `
          <label class="intern-ai-item intern-ai-item--update">
            <input type="checkbox" data-idx="${i}" data-group="update" checked />
            <span class="intern-badge intern-badge--warn">${typeName[item.type] || item.type}</span>
            <span>${escapeHtml(itemLabel(item))}</span>
            ${(item as unknown as { change_description?: string }).change_description ? `<span class="intern-meta" style="margin-left:8px">${escapeHtml((item as unknown as { change_description: string }).change_description)}</span>` : ''}
            <span class="intern-meta" style="margin-left:auto">${Math.round(item.confidence * 100)}%</span>
          </label>
        `).join('')}
      ` : ''}
      ${conflictItems.length > 0 ? `
        <h3 style="margin:12px 0 8px">⚠️ Abweichungen / Konflikte (${conflictItems.length})</h3>
        ${conflictItems.map((item, i) => `
          <label class="intern-ai-item intern-ai-item--conflict">
            <input type="checkbox" data-idx="${i}" data-group="conflict" />
            <span class="intern-badge intern-badge--danger">${typeName[item.type] || item.type}</span>
            <span>${escapeHtml(itemLabel(item))}</span>
            ${(item as unknown as { change_description?: string }).change_description ? `<span class="intern-meta" style="margin-left:8px">${escapeHtml((item as unknown as { change_description: string }).change_description)}</span>` : ''}
            <span class="intern-meta" style="margin-left:auto">${Math.round(item.confidence * 100)}%</span>
          </label>
        `).join('')}
      ` : ''}
      ${existingItems.length > 0 ? `
        <h3 style="margin:12px 0 8px">✅ Bereits vorhanden (${existingItems.length})</h3>
        ${existingItems.map((item) => `
          <div class="intern-ai-item intern-ai-item--exists">
            <span class="intern-badge intern-badge--info">${typeName[item.type] || item.type}</span>
            <span>${escapeHtml(itemLabel(item))}</span>
            <span class="intern-meta" style="margin-left:auto">identisch</span>
          </div>
        `).join('')}
      ` : ''}
      ${analysis.items.length === 0 ? '<p class="intern-empty">Keine strukturierten Daten erkannt.</p>' : ''}
    `;

    applyBtn.disabled = actionableItems.length === 0;
    applyBtn.textContent = `Ausgewählte übernehmen`;
    resultsEl.style.display = 'block';

    // Apply
    applyBtn.onclick = async () => {
      const checked = itemsList.querySelectorAll<HTMLInputElement>('input[type="checkbox"]:checked');
      const selectedItems: AiAnalysisItem[] = [];
      checked.forEach((cb) => {
        const group = cb.dataset.group;
        const idx = Number(cb.dataset.idx);
        if (group === 'new') selectedItems.push(newItems[idx]);
        else if (group === 'update') selectedItems.push(updateItems[idx]);
        else if (group === 'conflict') selectedItems.push(conflictItems[idx]);
      });
      if (selectedItems.length === 0) return;

      applyBtn.disabled = true;
      applyBtn.textContent = '⏳ Wird angelegt…';

      const result = await apiAiApply(selectedItems);
      const resultEl = context.root.querySelector<HTMLElement>('#ai-apply-result')!;
      if (result) {
        resultEl.innerHTML = successAlert(result.summary);
        applyBtn.textContent = '✓ Fertig';
      } else {
        resultEl.innerHTML = errorAlert('Fehler beim Anlegen der Daten.');
        applyBtn.disabled = false;
        applyBtn.textContent = 'Ausgewählte übernehmen';
      }
    };

    // Cancel
    context.root.querySelector<HTMLButtonElement>('#ai-cancel-btn')!.onclick = () => {
      resultsEl.style.display = 'none';
      statusEl.innerHTML = '';
    };
  }

  function itemLabel(item: { type: string; data: Record<string, unknown> }): string {
    const d = item.data;
    switch (item.type) {
      case 'building': return String(d.name || '') + (d.code ? ` (${d.code})` : '');
      case 'floor': return `${d.name || ''} → ${d.building_name || ''}`;
      case 'room': return `${d.room_number || ''} ${d.name || ''} → ${d.floor_name || ''}/${d.building_name || ''}`;
      case 'window': {
        let lbl = `${d.window_number || ''}`;
        if (d.manufacturer) lbl += ` [${d.manufacturer}]`;
        if (d.width_mm && d.height_mm) lbl += ` ${d.width_mm}×${d.height_mm}mm`;
        lbl += ` → ${d.room_name || ''}/${d.floor_name || ''}`;
        return lbl;
      }
      default: return JSON.stringify(d);
    }
  }

  bindHeaderLogout(context);
}

async function renderAdmin(context: AppContext) {
  const role = context.user?.profile.role ?? 'gast';
  const canManageUsers = role === 'administrator';
  const canViewUsers = role === 'administrator' || role === 'projektleiter';

  if (!canViewUsers) {
    context.root.innerHTML = errorAlert('Keine Berechtigung für das Benutzerverzeichnis.');
    return;
  }

  context.root.innerHTML = `
    ${renderHeader(context, canManageUsers ? 'Benutzerverwaltung' : 'Benutzerverzeichnis', canManageUsers ? 'Benutzerkonten anlegen, bearbeiten und deaktivieren.' : 'Alle Benutzerkonten im Überblick (nur Leserechte).')}
    <div id="admin-message"></div>
    ${canManageUsers ? `<div class="intern-card">
      <h2>Neuen Benutzer anlegen</h2>
      <form id="create-user-form" class="intern-form-grid" novalidate>
        <div class="intern-field">
          <label for="new-email">E-Mail</label>
          <input id="new-email" name="email" type="email" required autocomplete="off" />
        </div>
        <div class="intern-field">
          <label for="new-name">Vollständiger Name</label>
          <input id="new-name" name="full_name" type="text" required autocomplete="off" />
        </div>
        <div class="intern-field">
          <label for="new-role">Rolle</label>
          <select id="new-role" name="role">
            <option value="pruefer">Prüfer</option>
            <option value="sachverstaendiger">Sachverständiger</option>
            <option value="projektleiter">Projektleiter</option>
            <option value="gast">Gast (nur lesen)</option>
            <option value="administrator">Administrator</option>
          </select>
        </div>
        <div class="intern-field">
          <label for="new-password">Passwort (mind. 10 Zeichen)</label>
          <input id="new-password" name="password" type="password" minlength="10" required autocomplete="new-password" />
        </div>
        <div class="intern-actions intern-field--full">
          <button class="sv-button sv-button-primary" type="submit">Benutzer anlegen</button>
        </div>
      </form>
    </div>` : ''}
    <div class="intern-card">
      <h2>Bestehende Benutzer</h2>
      <div id="user-list">Lade Benutzerliste…</div>
    </div>
  `;

  const msgEl = context.root.querySelector<HTMLElement>('#admin-message');
  const listEl = context.root.querySelector<HTMLElement>('#user-list');

  const loadUsers = async () => {
    if (listEl) listEl.innerHTML = 'Lade…';
    const users = await apiListUsers();
    if (listEl) listEl.innerHTML = renderUserList(users, context.user!, canManageUsers);
    if (canManageUsers) {
      bindUserActions(context, users, loadUsers, msgEl);
    }
  };

  await loadUsers();

  if (canManageUsers) {
    const createForm = context.root.querySelector<HTMLFormElement>('#create-user-form');
    createForm?.addEventListener('submit', async (evt) => {
      evt.preventDefault();
      const data = new FormData(createForm);
      const email = String(data.get('email') ?? '').trim();
      const fullName = String(data.get('full_name') ?? '').trim();
      const role = String(data.get('role') ?? 'pruefer') as PortalRole;
      const password = String(data.get('password') ?? '');
      if (msgEl) msgEl.innerHTML = infoAlert('Benutzer wird angelegt…');
      const { error } = await apiCreateUser({ email, full_name: fullName, role, password });
      if (error) {
        if (msgEl) msgEl.innerHTML = errorAlert(`Fehler: ${error.message}`);
      } else {
        if (msgEl) msgEl.innerHTML = successAlert(`Benutzer ${email} erfolgreich angelegt.`);
        createForm.reset();
        await loadUsers();
      }
    });
  }
  bindHeaderLogout(context);
}

function renderUserList(users: AdminUser[], currentUser: PortalUser, canManageUsers = true): string {
  if (!users.length) return '<div class="intern-empty">Keine Benutzer vorhanden.</div>';
  return `
    <table class="intern-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>E-Mail</th>
          <th>Rolle</th>
          <th>Status</th>
          <th>Online</th>
          <th>Letzter Login</th>
          ${canManageUsers ? '<th>Aktionen</th>' : ''}
        </tr>
      </thead>
      <tbody>
        ${users.map((u) => {
          const isSelf = String(u.id) === currentUser.id;
          const statusBadge = u.is_active
            ? '<span class="intern-badge intern-badge--ok">Aktiv</span>'
            : '<span class="intern-badge intern-badge--warn">Deaktiviert</span>';
          const onlineBadge = isUserOnline(u.last_seen_at)
            ? `<span class="intern-badge intern-badge--info">Online · ${escapeHtml(formatPresenceTime(u.last_seen_at))}</span>`
            : '<span class="intern-badge intern-badge--warn">Offline</span>';
          return `
            <tr data-user-id="${u.id}">
              <td data-label="Name"><strong>${escapeHtml(u.full_name)}</strong></td>
              <td data-label="E-Mail">${escapeHtml(u.email)}</td>
              <td data-label="Rolle">${escapeHtml(u.role)}</td>
              <td data-label="Status">${statusBadge}</td>
              <td data-label="Online">${onlineBadge}</td>
              <td data-label="Letzter Login" class="intern-meta">${u.last_login_at ? formatDateTime(u.last_login_at) : '—'}</td>
              ${canManageUsers ? `<td data-label="Aktionen" class="intern-actions intern-actions--inline">
                <button class="sv-button sv-button-secondary" type="button" data-edit-user="${u.id}">Bearbeiten</button>
                <button class="sv-button sv-button-secondary" type="button" data-pw-user="${u.id}">Passwort</button>
                ${!isSelf && u.is_active ? `<button class="sv-button sv-button-secondary" type="button" data-deactivate-user="${u.id}">Deaktivieren</button>` : ''}
                ${!isSelf ? `<button class="sv-button sv-button-danger" type="button" data-delete-user="${u.id}" data-user-name="${escapeHtml(u.full_name)}">🗑 Löschen</button>` : ''}
              </td>` : ''}
            </tr>
            ${canManageUsers ? `<tr id="edit-row-${u.id}" class="intern-edit-row" hidden></tr>` : ''}
          `;
        }).join('')}
      </tbody>
    </table>
  `;
}

function bindUserActions(
  context: AppContext,
  users: AdminUser[],
  reload: () => Promise<void>,
  msgEl: HTMLElement | null,
) {
  // Edit user
  context.root.querySelectorAll<HTMLElement>('[data-edit-user]').forEach((btn) => {
    btn.onclick = () => {
      const id = Number(btn.dataset.editUser);
      const user = users.find((u) => u.id === id);
      if (!user) return;
      const row = context.root.querySelector<HTMLTableRowElement>(`#edit-row-${id}`);
      if (!row) return;
      if (!row.hidden) { row.hidden = true; return; }
      row.hidden = false;
      row.innerHTML = `
        <td colspan="6">
          <form class="intern-form-grid intern-edit-form" data-save-user="${id}">
            <div class="intern-field">
              <label>Name</label>
              <input name="full_name" value="${escapeHtml(user.full_name)}" required />
            </div>
            <div class="intern-field">
              <label>Rolle</label>
              <select name="role">
                <option value="pruefer"          ${user.role === 'pruefer'          ? 'selected' : ''}>Prüfer</option>
                <option value="sachverstaendiger" ${user.role === 'sachverstaendiger'? 'selected' : ''}>Sachverständiger</option>
                <option value="projektleiter"    ${user.role === 'projektleiter'    ? 'selected' : ''}>Projektleiter</option>
                <option value="gast"             ${user.role === 'gast' || user.role === 'auswertung' ? 'selected' : ''}>Gast (nur lesen)</option>
                <option value="administrator"    ${user.role === 'administrator'    ? 'selected' : ''}>Administrator</option>
              </select>
            </div>
            <div class="intern-field">
              <label>Status</label>
              <select name="is_active">
                <option value="1" ${user.is_active ? 'selected' : ''}>Aktiv</option>
                <option value="0" ${!user.is_active ? 'selected' : ''}>Deaktiviert</option>
              </select>
            </div>
            <div class="intern-actions intern-field--full">
              <button class="sv-button sv-button-primary" type="submit">Speichern</button>
            </div>
          </form>
        </td>
      `;
      row.querySelector<HTMLFormElement>(`[data-save-user="${id}"]`)?.addEventListener('submit', async (evt) => {
        evt.preventDefault();
        const form = evt.currentTarget as HTMLFormElement;
        const fd = new FormData(form);
        const payload = {
          full_name: String(fd.get('full_name') ?? '').trim(),
          role: String(fd.get('role') ?? 'pruefer') as PortalRole,
          is_active: fd.get('is_active') === '1',
        };
        const { error } = await apiUpdateUser(id, payload);
        if (error) {
          if (msgEl) msgEl.innerHTML = errorAlert(`Fehler: ${error.message}`);
        } else {
          if (msgEl) msgEl.innerHTML = successAlert('Benutzer aktualisiert.');
          await reload();
        }
      });
    };
  });

  // Set password
  context.root.querySelectorAll<HTMLElement>('[data-pw-user]').forEach((btn) => {
    btn.onclick = () => {
      const id = Number(btn.dataset.pwUser);
      const row = context.root.querySelector<HTMLTableRowElement>(`#edit-row-${id}`);
      if (!row) return;
      if (!row.hidden && row.querySelector('[data-pw-form]')) { row.hidden = true; return; }
      row.hidden = false;
      // Generate a random password
      const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*';
      let generated = '';
      for (let i = 0; i < 14; i++) generated += chars[Math.floor(Math.random() * chars.length)];
      row.innerHTML = `
        <td colspan="6">
          <div style="padding:12px;background:#f8fafc;border-radius:8px">
            <h4 style="margin:0 0 12px">Passwort verwalten</h4>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
              <div style="flex:1;min-width:200px">
                <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px">Neues Passwort</label>
                <div style="display:flex;gap:6px">
                  <input id="pw-input-${id}" name="password" type="password" minlength="10" required autocomplete="new-password" value="${generated}" style="flex:1;padding:8px 12px;border:1px solid #ccc;border-radius:6px;font-family:monospace" />
                  <button type="button" class="sv-button sv-button-secondary" id="pw-toggle-${id}" style="padding:4px 10px;font-size:0.85rem" title="Passwort anzeigen/verbergen">👁️</button>
                </div>
              </div>
              <button type="button" class="sv-button sv-button-secondary" id="pw-gen-${id}" style="padding:6px 12px;font-size:0.85rem">🔄 Generieren</button>
              <button type="button" class="sv-button sv-button-secondary" id="pw-copy-${id}" style="padding:6px 12px;font-size:0.85rem">📋 Kopieren</button>
              <button type="button" class="sv-button sv-button-primary" id="pw-save-${id}" style="padding:6px 12px;font-size:0.85rem">💾 Speichern</button>
            </div>
            <p style="margin:8px 0 0;font-size:0.8rem;color:#666">Generiertes Passwort: <code id="pw-preview-${id}" style="user-select:all;background:#e8eef3;padding:2px 6px;border-radius:4px">${generated}</code></p>
          </div>
        </td>
      `;
      const input = row.querySelector<HTMLInputElement>(`#pw-input-${id}`);
      // Toggle visibility
      row.querySelector(`#pw-toggle-${id}`)?.addEventListener('click', () => {
        if (input) { input.type = input.type === 'password' ? 'text' : 'password'; }
      });
      // Generate new
      row.querySelector(`#pw-gen-${id}`)?.addEventListener('click', () => {
        let np = '';
        for (let i = 0; i < 14; i++) np += chars[Math.floor(Math.random() * chars.length)];
        if (input) input.value = np;
        const preview = row.querySelector(`#pw-preview-${id}`);
        if (preview) preview.textContent = np;
      });
      // Copy
      row.querySelector(`#pw-copy-${id}`)?.addEventListener('click', () => {
        if (input) { navigator.clipboard.writeText(input.value); if (msgEl) msgEl.innerHTML = successAlert('Passwort in Zwischenablage kopiert.'); }
      });
      // Save
      row.querySelector(`#pw-save-${id}`)?.addEventListener('click', async () => {
        if (!input || input.value.length < 10) { if (msgEl) msgEl.innerHTML = errorAlert('Passwort muss mindestens 10 Zeichen lang sein.'); return; }
        const { error } = await apiSetUserPassword(id, input.value);
        if (error) {
          if (msgEl) msgEl.innerHTML = errorAlert(`Fehler: ${error.message}`);
        } else {
          if (msgEl) msgEl.innerHTML = successAlert('Passwort wurde geändert.');
          row.hidden = true;
        }
      });
    };
  });

  // Deactivate
  context.root.querySelectorAll<HTMLElement>('[data-deactivate-user]').forEach((btn) => {
    btn.onclick = async () => {
      const id = Number(btn.dataset.deactivateUser);
      const user = users.find((u) => u.id === id);
      if (!user) return;
      if (!window.confirm(`Benutzer „${user.full_name}" (${user.email}) wirklich deaktivieren?`)) return;
      const { error } = await apiDeactivateUser(id);
      if (error) {
        if (msgEl) msgEl.innerHTML = errorAlert(`Fehler: ${error.message}`);
      } else {
        if (msgEl) msgEl.innerHTML = successAlert('Benutzer deaktiviert.');
        await reload();
      }
    };
  });

  // Permanent delete
  context.root.querySelectorAll<HTMLElement>('[data-delete-user]').forEach((btn) => {
    btn.onclick = async () => {
      const id = Number(btn.dataset.deleteUser);
      const userName = btn.dataset.userName ?? '';
      if (!window.confirm(`⚠️ ACHTUNG: Benutzer „${userName}" endgültig löschen?\n\nDiese Aktion kann NICHT rückgängig gemacht werden!\nAlle Zuweisungen werden entfernt.`)) return;
      if (!window.confirm(`Sind Sie WIRKLICH sicher? Benutzer „${userName}" wird unwiderruflich gelöscht.`)) return;
      const { error } = await apiDeleteUserPermanent(id);
      if (error) {
        if (msgEl) msgEl.innerHTML = errorAlert(`Fehler: ${error.message}`);
      } else {
        if (msgEl) msgEl.innerHTML = successAlert('Benutzer endgültig gelöscht.');
        await reload();
      }
    };
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
  const isAdmin = context.user?.profile.role === 'administrator';
  const canManage = ['administrator', 'projektleiter'].includes(context.user?.profile.role ?? '');
  const canImport = ['administrator', 'pruefer'].includes(context.user?.profile.role ?? '');
  const slug = getProjectSlug();
  const inProject = context.route !== 'projects' && context.route !== 'new-project' && context.route !== 'login' && context.route !== 'landing';
  const pb = `/intern/${slug}`;
  
  // Alphabetically: Auswertung, Dashboard, Export, Fenster, Gebäude, KI-Import, Projekte
  const projectNav = inProject ? `
    <a class="sv-button sv-button-secondary" href="${pb}/auswertung/">Auswertung</a>
    <a class="sv-button sv-button-secondary" href="${pb}/">Dashboard</a>
    <a class="sv-button sv-button-secondary" href="${pb}/export/">Export</a>
    <a class="sv-button sv-button-secondary" href="${pb}/fenster/">Fenster</a>
    <a class="sv-button sv-button-secondary" href="${pb}/gebaeude/">Gebäude</a>
    ${canImport ? `<a class="sv-button sv-button-secondary" href="${pb}/import/">📄 KI-Import</a>` : ''}
    ${canManage ? `<a class="sv-button sv-button-secondary" href="/intern/projekte/neu/">＋ Neues Projekt</a>` : ''}
    <a class="sv-button sv-button-secondary" href="/intern/projekte/">Projekte</a>
  ` : `
    ${canManage ? `<a class="sv-button sv-button-primary" href="/intern/projekte/neu/">＋ Neues Projekt</a>` : ''}
    <a class="sv-button sv-button-secondary" href="/intern/projekte/">Projekte</a>
  `;

  const utilityActions = `
    <div class="intern-hero__utility">
      ${isAdmin ? `<a class="sv-button sv-button-secondary intern-hero__utility-link" href="${inProject ? pb : '/intern/fensterpruefung-bonn'}/admin/">👤 Benutzerverwaltung</a>` : ''}
      <button class="sv-button sv-button-ghost intern-hero__utility-link" type="button" id="header-logout">Abmelden</button>
    </div>
  `;

  return `
    <div class="intern-card intern-hero">
      <p class="sv-eyebrow">SV-Netzwerk Prüfportal</p>
      <h1>${escapeHtml(title)}</h1>
      <p>${escapeHtml(text)}</p>
      <nav class="intern-actions" aria-label="Hauptnavigation">
        ${projectNav}
      </nav>
      ${utilityActions}
    </div>
  `;
}

function renderStat(label: string, value: number, href?: string) {
  if (href) {
    return `<a class="intern-stat intern-stat--clickable" href="${escapeHtml(href)}"><span>${escapeHtml(label)}</span><strong>${value}</strong></a>`;
  }
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
    case 'building_label': return 'Gebäude';
    case 'section_label': return 'Gebäudeteil';
    case 'floor_label': return 'Etage';
    case 'assigned_name': return 'Prüfer';
    case 'status': return 'Prüfstatus';
    case 'overall_rating': return 'Bewertung';
    default: return key;
  }
}

function renderWindowTable(records: WindowSummary[]) {
  if (!records.length) return '<div class="intern-empty">Keine Datensätze gefunden.</div>';
  return `
    <div class="intern-table-wrap">
      <table class="intern-table">
        <thead>
          <tr>
            <th>Fenster</th>
            <th>Standort</th>
            <th>Status</th>
            <th>Prüfer</th>
            <th>Letzte Änderung</th>
            <th>Sperre</th>
            <th>Aktionen</th>
          </tr>
        </thead>
        <tbody>
          ${records.map((record) => `
            <tr style="cursor:pointer" data-open-window="${escapeHtml(record.id)}">
              <td><strong>${escapeHtml(record.window_number || record.record_id)}</strong><br/><span class="intern-meta">${escapeHtml(record.record_id)}</span></td>
              <td>${escapeHtml([record.building_label, record.section_label, record.floor_label, record.room_number].filter(Boolean).join(' · '))}</td>
              <td>${escapeHtml(record.status)}${record.special_inspection_required ? '<br/><span class="intern-badge intern-badge--warn">Spezialprüfung</span>' : ''}${record.urgent_action_required ? '<br/><span class="intern-badge intern-badge--danger">Sofort</span>' : ''}</td>
              <td>${escapeHtml(record.assigned_name ?? '—')}</td>
              <td>${formatDateTime(record.updated_at)}</td>
              <td>${record.lock_owner_name ? `<span class="intern-badge intern-badge--info">${escapeHtml(record.lock_owner_name)} bis ${formatTime(record.lock_expires_at)}</span>` : '<span class="intern-badge intern-badge--ok">frei</span>'}</td>
              <td>
                <div class="intern-actions">
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
  const header = ['Datensatz', 'Fensternummer', 'Gebäude', 'Gebäudeteil', 'Etage', 'Raumnummer', 'Status', 'Bewertung', 'Priorität', 'Prüfer', 'Letzte Änderung'];
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
    <html lang="de"><head><title>SV-Netzwerk – Sammelprotokoll</title><style>
      body{font-family:Arial,sans-serif;padding:24px;color:#071a2e}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d6e0e8;padding:8px;text-align:left}h1{margin-top:0}
    </style></head><body>
    <h1>SV-Netzwerk – Sammelprotokoll</h1>
    <p>Datenstand: ${escapeHtml(new Date().toLocaleString('de-DE'))}</p>
    <table><thead><tr><th>Fenster</th><th>Standort</th><th>Status</th><th>Bewertung</th><th>Prioritaet</th></tr></thead><tbody>
    ${records.map((record) => `<tr><td>${escapeHtml(record.window_number || record.record_id)}</td><td>${escapeHtml([record.building_label, record.section_label, record.floor_label, record.room_number].filter(Boolean).join(' · '))}</td><td>${escapeHtml(record.status)}</td><td>${escapeHtml(record.overall_rating ?? '')}</td><td>${escapeHtml(record.priority ?? '')}</td></tr>`).join('')}
    </tbody></table></body></html>
  `);
  popup.document.close();
  popup.focus();
  popup.print();
}

function printSashReport(sash: WindowSashRecord, data: Record<string, unknown>, photos: PhotoItem[]): void {
  const popup = window.open('', '_blank', 'noopener,noreferrer,width=900,height=1200');
  if (!popup) { alert('Bitte Pop-ups für diese Seite erlauben.'); return; }

  const d = (key: string, fallback = '—') => escapeHtml(String(data[key] ?? '') || fallback);
  const chk = (key: string) => Boolean(data[key]) ? '☑' : '☐';
  const compLabel: Record<string, string> = {
    fluegellager:'Flügellager', scherenlager:'Scherenlager', ecklager:'Ecklager',
    schliessbleche:'Schließbleche', verriegelungen:'Verriegelungen', getriebe:'Getriebe',
    griff:'Griff', dichtungen:'Dichtungen', rahmen:'Rahmen', glas:'Glas',
    oeffnungsbegrenzer:'Öffnungsbegrenzer',
  };
  const stateLabel: Record<string, string> = {
    OK:'OK', eingeschraenkt_funktionsfaehig:'Eingeschränkt', defekt:'Defekt', fehlt:'Fehlt', nicht_vorhanden:'Nicht eingebaut',
  };
  const suitLabel: Record<string, string> = {
    geeignet:'Geeignet', geeignet_nach_nachstellung:'Nach Nachstellung geeignet',
    instandsetzung_erforderlich:'Instandsetzung erforderlich', austausch_empfohlen:'Austausch empfohlen',
  };

  const compRows = Object.entries(compLabel).map(([key, label]) => {
    const status = stateLabel[String(data[`${key}_status`] ?? '')] ?? (data[`${key}_status`] ? d(`${key}_status`) : '—');
    const bem = d(`${key}_bemerkung`, '');
    return `<tr><td>${escapeHtml(label)}</td><td>${escapeHtml(status)}</td><td>${escapeHtml(bem)}</td></tr>`;
  }).join('');

  const fnChecks = [
    ['fn_oeffnet_vollstaendig',      'Öffnet vollständig'],
    ['fn_schliesst_vollstaendig',    'Schließt vollständig'],
    ['fn_verriegelung_funktioniert', 'Verriegelung funktioniert'],
    ['fn_griff_leichtgaengig',       'Griffbewegung leichtgängig'],
    ['fn_kein_widerstand',           'Kein abnormaler Widerstand'],
    ['fn_kein_spiel',                'Kein übermäßiges Spiel'],
    ['fn_kein_schleifen',            'Kein Schleifen'],
    ['fn_fluchtweg_moeglich',        'Rettungsweg möglich'],
  ].map(([key, label]) => `<tr><td>${escapeHtml(label)}</td><td>${chk(key)}</td></tr>`).join('');

  const photoHtml = photos.length > 0
    ? photos.slice(0, 12).map((p) => `<div class="photo-item"><img src="/intern/photos/${escapeHtml(p.storage_path)}" alt="${escapeHtml(p.caption ?? p.category)}" /><p>${escapeHtml(p.caption ?? p.category)}</p></div>`).join('')
    : '<p>Keine Fotos vorhanden.</p>';

  popup.document.write(`<!DOCTYPE html>
<html lang="de"><head>
<meta charset="utf-8" />
<title>Prüfbericht – ${escapeHtml(sash.sash_label || `Flügel ${sash.sash_number}`)}</title>
<style>
  @page{margin:20mm 15mm;size:A4}
  *{box-sizing:border-box}
  body{font-family:'Arial',sans-serif;font-size:11pt;color:#1a1a1a;margin:0;padding:0}
  .header{border-bottom:3px solid #071a2e;padding-bottom:12px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-end}
  .header h1{margin:0;font-size:14pt;color:#071a2e}
  .header .company{font-size:9pt;text-align:right;color:#555}
  .meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 24px;margin-bottom:16px;border:1px solid #ccc;padding:10px;border-radius:4px}
  .meta-item{display:flex;flex-direction:column}
  .meta-item .label{font-size:8pt;color:#666;text-transform:uppercase;letter-spacing:.05em}
  .meta-item .value{font-weight:bold}
  h2{font-size:11pt;background:#071a2e;color:#fff;padding:5px 10px;margin:16px 0 6px}
  table{width:100%;border-collapse:collapse;font-size:10pt}
  th,td{border:1px solid #ccc;padding:5px 8px;text-align:left;vertical-align:top}
  th{background:#f0f0f0;font-weight:bold}
  .verdict-box{border:2px solid #071a2e;padding:12px;border-radius:4px;margin:12px 0}
  .verdict-box .label{font-size:8pt;text-transform:uppercase;color:#666}
  .verdict-box .value{font-size:13pt;font-weight:bold;color:#071a2e}
  .photo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
  .photo-item img{width:100%;height:120px;object-fit:cover;border:1px solid #ccc}
  .photo-item p{font-size:8pt;text-align:center;margin:2px 0}
  .footer{border-top:1px solid #ccc;margin-top:16px;padding-top:8px;font-size:8pt;color:#666;display:flex;justify-content:space-between}
  @media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head><body>

<div class="header">
  <div>
    <h1>Prüfbericht – Fensterflügel-Inspektion</h1>
    <p style="margin:4px 0 0;font-size:10pt;color:#555">SV-Netzwerk Prüfportal</p>
  </div>
  <div class="company">
    SV-Büro Marc Schütt e.K.<br/>
    Sachverständigen- und Prüfsystem
  </div>
</div>

<div class="meta-grid">
  <div class="meta-item"><span class="label">Gebäude</span><span class="value">${escapeHtml(sash.building_name ?? '—')}</span></div>
  <div class="meta-item"><span class="label">Etage</span><span class="value">${escapeHtml(sash.floor_name ?? '—')}</span></div>
  <div class="meta-item"><span class="label">Raum</span><span class="value">${escapeHtml(`${sash.room_name ?? ''} ${sash.room_number ? `(${sash.room_number})` : ''}`.trim() || '—')}</span></div>
  <div class="meta-item"><span class="label">Fenster-ID</span><span class="value">${escapeHtml(sash.window_number)}</span></div>
  <div class="meta-item"><span class="label">Flügel</span><span class="value">${escapeHtml(sash.sash_label || `Flügel ${sash.sash_number}`)}</span></div>
  <div class="meta-item"><span class="label">Prüfer</span><span class="value">${d('inspector_name')}</span></div>
  <div class="meta-item"><span class="label">Prüfdatum</span><span class="value">${d('inspection_date')}</span></div>
  <div class="meta-item"><span class="label">Uhrzeit</span><span class="value">${d('inspection_time', '—')}</span></div>
</div>

<h2>I. Beschlagprüfung</h2>
<table>
  <thead><tr><th>Bauteil</th><th>Zustand</th><th>Bemerkung</th></tr></thead>
  <tbody>${compRows}</tbody>
</table>

<h2>II. Fensterfunktion</h2>
<table>
  <thead><tr><th>Kriterium</th><th>Ergebnis</th></tr></thead>
  <tbody>${fnChecks}</tbody>
</table>

<div class="verdict-box">
  <div class="label">Eignung</div>
  <div class="value">${escapeHtml(suitLabel[d('eignung_beurteilung', '')] ?? d('eignung_beurteilung', '—'))}</div>
  <div style="margin-top:8px"><span class="label">Risikostufe: </span><strong>${d('risikostufe', '—')}</strong></div>
</div>

<h2>III. Maßnahmen</h2>
<table>
  <tr><th style="width:30%">Empfohlene Maßnahme</th><td>${d('massnahme_empfehlung')}</td></tr>
  <tr><th>Geschätzter Aufwand</th><td>${d('massnahme_aufwand', '—')}</td></tr>
  <tr><th>Priorität</th><td>${d('massnahme_prioritaet', '—')}</td></tr>
  <tr><th>Verantwortlich</th><td>${d('massnahme_verantwortlich', '—')}</td></tr>
</table>

<h2>IV. Fotodokumentation</h2>
<div class="photo-grid">${photoHtml}</div>

<div class="footer">
  <span>SV-Büro Marc Schütt e.K. – Sachverständigen- und Prüfsystem</span>
  <span>Gedruckt: ${new Date().toLocaleString('de-DE')}</span>
</div>

</body></html>`);
  popup.document.close();
  popup.focus();
  setTimeout(() => popup.print(), 300);
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
    await QRCode.toCanvas(canvas, `${window.location.origin}${projectBase()}/fenster/${encodeURIComponent(record.id)}/`, { width: 160, margin: 1 });
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

function renderAnalysisCard(label: string, value: number, filterKey: string) {
  return `<article class="intern-stat intern-stat--clickable" data-filter="${escapeHtml(filterKey)}" style="cursor:pointer"><span>${escapeHtml(label)}</span><strong>${value}</strong></article>`;
}

function renderGrouping(groups: Map<string, WindowSummary[]>, groupType: string) {
  if (!groups.size) return '<div class="intern-empty">Keine Daten vorhanden.</div>';
  return `<div class="intern-list">${Array.from(groups.entries()).sort(([a], [b]) => a.localeCompare(b)).map(([label, items]) => `<div class="intern-card intern-card--clickable" data-group-type="${escapeHtml(groupType)}" data-group-value="${escapeHtml(label)}" style="cursor:pointer"><strong>${escapeHtml(label)}</strong><p class="intern-meta">${items.length} Fenster · ${items.filter((item) => item.has_defect).length} mit Mangel · ${items.filter((item) => item.special_inspection_required).length} Spezialpruefungen</p></div>`).join('')}</div>`;
}

function roleBadge(role: PortalRole) {
  return `<span class="intern-badge intern-badge--info">${escapeHtml(roleLabels[role] ?? role)}</span>`;
}

function connectionBadge() {
  return navigator.onLine
    ? '<span class="intern-badge intern-badge--ok">Online · Verbunden</span>'
    : '<span class="intern-badge intern-badge--warn">Offline · Lokale Speicherung aktiv</span>';
}

function renderOnlineUsersCard(users: OnlinePortalUser[], currentUserId: string | null) {
  const visibleUsers = users.slice(0, 5);
  const countLabel = users.length === 1 ? '1 Benutzer online' : `${users.length} Benutzer online`;
  return `
    <strong>${countLabel}</strong>
    <p class="intern-meta">Aktiv in den letzten ${ONLINE_WINDOW_MINUTES} Minuten</p>
    <div class="intern-online-users">
      ${visibleUsers.map((user) => `
        <span class="intern-online-users__item">
          <span class="intern-online-users__dot" aria-hidden="true"></span>
          ${escapeHtml(user.full_name)}${currentUserId === String(user.id) ? ' (Sie)' : ''}
        </span>
      `).join('')}
      ${users.length > visibleUsers.length ? `<span class="intern-online-users__more">+${users.length - visibleUsers.length} weitere</span>` : ''}
      ${users.length === 0 ? '<span class="intern-online-users__empty">Derzeit niemand aktiv.</span>' : ''}
    </div>
  `;
}

function infoAlert(text: string) { return `<div class="intern-alert intern-alert--info">${escapeHtml(text)}</div>`; }
function successAlert(text: string) { return `<div class="intern-alert intern-alert--success">${escapeHtml(text)}</div>`; }
function warnAlert(text: string) { return `<div class="intern-alert intern-alert--warn">${escapeHtml(text)}</div>`; }
function errorAlert(text: string) { return `<div class="intern-alert intern-alert--error">${escapeHtml(text)}</div>`; }

/** Prüft ob der Benutzer Daten bearbeiten/anlegen/löschen darf (alles außer Gast/Auswertung). */
function canEdit(context: AppContext): boolean {
  const role = context.user?.profile.role ?? 'gast';
  return !['gast', 'auswertung'].includes(role);
}

/** Prüft ob der Benutzer Admin-Funktionen nutzen darf (nur Admin/Projektleiter). */
function isAdminRole(context: AppContext): boolean {
  const role = context.user?.profile.role ?? 'gast';
  return ['administrator', 'projektleiter'].includes(role);
}

function subscribeToWindowChanges(_context: AppContext, _handler: () => void) {
  // Statusaktualisierung erfolgt über Polling; kein separater Kanal erforderlich.
}

function subscribeToSingleRecord(_context: AppContext, _id: string, _handler: () => Promise<void>) {
  // Statusaktualisierung erfolgt über Polling; kein separater Kanal erforderlich.
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
  if (role === 'administrator' || role === 'projektleiter') return true;
  if (role === 'gast' || role === 'auswertung') return false;
  // sachverstaendiger und pruefer dürfen bearbeiten, solange nicht freigegeben
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
  return new Intl.DateTimeFormat('de-DE', { dateStyle: 'short', timeStyle: 'short' }).format(parsePortalDate(value));
}

function formatTime(value: string | null | undefined) {
  if (!value) return '—';
  return new Intl.DateTimeFormat('de-DE', { timeStyle: 'short' }).format(parsePortalDate(value));
}

function formatPresenceTime(value: string | null) {
  const date = value ? parsePortalDate(value) : null;
  if (!date) return 'gerade eben';
  const diffMinutes = Math.max(0, Math.round((Date.now() - date.getTime()) / 60000));
  if (diffMinutes <= 1) return 'gerade eben';
  return `vor ${diffMinutes} Min.`;
}

function isUserOnline(value: string | null) {
  if (!value) return false;
  const date = parsePortalDate(value);
  return (Date.now() - date.getTime()) <= ONLINE_WINDOW_MINUTES * 60 * 1000;
}

function parsePortalDate(value: string) {
  if (value.includes('T')) {
    return new Date(value);
  }
  return new Date(value.replace(' ', 'T') + 'Z');
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

function escapeAttr(value: string) {
  return value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll("'", '&#39;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
}
