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
  apiListUsers,
  apiCreateUser,
  apiUpdateUser,
  apiSetUserPassword,
  apiDeactivateUser,
  loadApiUser,
  onAuthChange,
  // Hierarchie
  apiListBuildings,
  apiListFloors,
  apiListRooms,
  apiListWindowsInRoom,
  apiCreateBuilding,
  apiUpdateBuilding,
  apiDeleteBuilding,
  apiCreateFloor,
  apiUpdateFloor,
  apiDeleteFloor,
  apiCreateRoom,
  apiUpdateRoom,
  apiDeleteRoom,
  apiDeleteWindow,
  apiCreateWindowInRoom,
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
} from './types';

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
const SYNC_WARNING_MESSAGE = 'Es liegen noch nicht synchronisierte Aenderungen vor.';

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
    case 'admin':
      await renderAdmin(context);
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
  const [buildings, records] = await Promise.all([
    apiListBuildings(),
    fetchWindowSummaries(context),
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
    ${renderHeader(context, 'Projekt-Dashboard', 'Prüffortschritt – Fensterbeschlagsprüfung BMVg Bonn.')}
    ${setupBanner}
    <div class="intern-statusbar">
      <div class="intern-card">${connectionBadge()}</div>
      <div class="intern-card">${roleBadge(context.user?.profile.role ?? 'pruefer')}<p class="intern-meta">${escapeHtml(context.user?.profile.full_name ?? context.user?.email ?? '')}</p></div>
      <div class="intern-card"><strong>${stats.total}</strong><p class="intern-meta">Fenster gesamt</p></div>
      <div class="intern-card"><strong>${stats.completed}</strong><p class="intern-meta">Flügel geprüft</p></div>
    </div>

    ${buildings.length > 0 ? `
    <h2 style="margin:24px 0 12px">Gebäude</h2>
    <div class="intern-building-grid">
      ${buildings.map((b) => {
        const pct = b.progress_pct;
        const badgeClass = pct === 100 ? 'ok' : pct > 0 ? 'info' : 'warn';
        return `
          <a class="intern-building-card" href="/intern/fensterpruefung-bonn/etagen/?building_id=${b.id}">
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
        `;
      }).join('')}
    </div>
    ` : `<div class="intern-empty">Noch keine Gebäude angelegt. Bitte zuerst ein Gebäude über die Verwaltung anlegen.</div>`}

    <div class="intern-grid" style="margin-top:24px">
      <section class="intern-panel">
        <h2>Gesamtstatistik</h2>
        <div class="intern-stats">
          ${renderStat('Fenster gesamt', stats.total)}
          ${renderStat('Nicht begonnen', stats.notStarted)}
          ${renderStat('In Bearbeitung', stats.inProgress)}
          ${renderStat('Vollständig geprüft', stats.completed)}
          ${renderStat('Mit Mangel', stats.withDefect)}
          ${renderStat('Dringender Handlungsbedarf', stats.urgent)}
          ${renderStat('Spezialpruefung', stats.specialInspection)}
        </div>
      </section>
      <section class="intern-panel">
        <h2>Letzte Änderungen</h2>
        <div class="intern-list">
          ${stats.recentChanges.map((item) => `<a class="intern-card" href="/intern/fensterpruefung-bonn/fenster/record/?id=${encodeURIComponent(item.id)}"><strong>${escapeHtml(item.label)}</strong><p class="intern-meta">${formatDateTime(item.updatedAt)} · ${escapeHtml(item.status)}${item.user ? ` · ${escapeHtml(item.user)}` : ''}</p></a>`).join('') || '<div class="intern-empty">Noch keine Änderungen protokolliert.</div>'}
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

  if (isAdmin) {
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
  return `
    <div class="intern-building-card-wrapper" data-building-id="${b.id}" data-building-name="${escapeHtml(b.name)}" data-building-code="${escapeHtml(b.code ?? '')}">
      <a class="intern-building-card" href="/intern/fensterpruefung-bonn/etagen/?building_id=${b.id}">
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
          <button data-action="delete">🗑️ Löschen</button>
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

function bindEntityActions(context: AppContext, wrapperSelector: string, handlers: { onEdit?: (w: HTMLElement) => void; onDelete?: (w: HTMLElement) => void }) {
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

  if (handlers.onEdit) {
    context.root.querySelectorAll<HTMLElement>(`${wrapperSelector} [data-action="edit"]`).forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const wrapper = btn.closest<HTMLElement>(wrapperSelector)!;
        handlers.onEdit!(wrapper);
      });
    });
  }

  if (handlers.onDelete) {
    context.root.querySelectorAll<HTMLElement>(`${wrapperSelector} [data-action="delete"]`).forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const wrapper = btn.closest<HTMLElement>(wrapperSelector)!;
        handlers.onDelete!(wrapper);
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
      <a href="/intern/fensterpruefung-bonn/gebaeude/">Gebäude</a> › Etagen
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
  return `
    <div class="intern-list-item-wrapper" data-floor-id="${f.id}" data-entity-name="${escapeHtml(f.name)}" data-entity-level="${f.level ?? 0}">
      <a class="intern-card intern-list-item" href="/intern/fensterpruefung-bonn/raeume/?floor_id=${f.id}&building_id=${buildingId}">
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
          <button data-action="delete">🗑️ Löschen</button>
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
      <a href="/intern/fensterpruefung-bonn/gebaeude/">Gebäude</a> ›
      <a href="/intern/fensterpruefung-bonn/etagen/?building_id=${buildingId}">Etagen</a> ›
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
  return `
    <div class="intern-list-item-wrapper" data-room-id="${r.id}" data-entity-name="${escapeHtml(r.name)}" data-entity-number="${escapeHtml(r.room_number ?? '')}">
      <a class="intern-card intern-list-item" href="/intern/fensterpruefung-bonn/fenster/?room_id=${r.id}&floor_id=${floorId}&building_id=${buildingId}">
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
          <button data-action="delete">🗑️ Löschen</button>
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
      <a href="/intern/fensterpruefung-bonn/gebaeude/">Gebäude</a> ›
      <a href="/intern/fensterpruefung-bonn/etagen/?building_id=${buildingId}">Etagen</a> ›
      <a href="/intern/fensterpruefung-bonn/raeume/?floor_id=${floorId}&building_id=${buildingId}">Räume</a> ›
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
      redirectTo(`/intern/fensterpruefung-bonn/fluegel/?window_id=${result.id}&room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}`);
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
      <a class="intern-card intern-list-item" href="/intern/fensterpruefung-bonn/fluegel/?window_id=${w.id}&room_id=0&floor_id=${w.floor_id ?? 0}&building_id=${w.building_id ?? 0}">
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
  const breadBuilding = buildingId > 0 ? `<a href="/intern/fensterpruefung-bonn/etagen/?building_id=${buildingId}">Etagen</a> › ` : '';
  const breadFloor = floorId > 0 ? `<a href="/intern/fensterpruefung-bonn/raeume/?floor_id=${floorId}&building_id=${buildingId}">Räume</a> › ` : '';
  const breadRoom = roomId > 0 ? `<a href="/intern/fensterpruefung-bonn/fenster/?room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}">Fenster</a> › ` : '';

  const overallPct = sashes.length > 0
    ? Math.round(sashes.filter((s) => ['abgeschlossen', 'freigegeben'].includes(s.status)).length / sashes.length * 100)
    : 0;

  context.root.innerHTML = `
    ${renderHeader(context, `Flügel – ${escapeHtml(windowLabel)}`, 'Bitte Flügel wählen für die Inspektion.')}
    <div class="intern-breadcrumb">
      <a href="/intern/fensterpruefung-bonn/gebaeude/">Gebäude</a> ›
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
      redirectTo(`/intern/fensterpruefung-bonn/fluegel-pruefung/?sash_id=${result.id}&window_id=${windowId}&room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}`);
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
    <a class="intern-card intern-list-item" href="/intern/fensterpruefung-bonn/fluegel-pruefung/?sash_id=${s.id}&window_id=${windowId}&room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}">
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

  const backUrl = `/intern/fensterpruefung-bonn/fluegel/?window_id=${windowId}&room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}`;

  context.root.innerHTML = `
    ${renderHeader(context, `${escapeHtml(sash.sash_label || `Flügel ${sash.sash_number}`)} – Prüfung`, `Fenster ${escapeHtml(sash.window_number)} · ${escapeHtml(sash.room_name ?? '')} · ${escapeHtml(sash.floor_name ?? '')} · ${escapeHtml(sash.building_name ?? '')}`)}
    <div class="intern-breadcrumb">
      <a href="/intern/fensterpruefung-bonn/gebaeude/">Gebäude</a> ›
      ${buildingId > 0 ? `<a href="/intern/fensterpruefung-bonn/etagen/?building_id=${buildingId}">Etagen</a> › ` : ''}
      ${floorId > 0 ? `<a href="/intern/fensterpruefung-bonn/raeume/?floor_id=${floorId}&building_id=${buildingId}">Räume</a> › ` : ''}
      ${roomId > 0 ? `<a href="/intern/fensterpruefung-bonn/fenster/?room_id=${roomId}&floor_id=${floorId}&building_id=${buildingId}">Fenster</a> › ` : ''}
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
  subscribeToWindowChanges(context, () => void renderWindowsFlat(context));
  bindHeaderLogout(context);
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
  bindHeaderLogout(context);
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

async function renderAdmin(context: AppContext) {
  if (context.user?.profile.role !== 'administrator') {
    context.root.innerHTML = errorAlert('Nur Administratoren können die Benutzerverwaltung aufrufen.');
    return;
  }

  context.root.innerHTML = `
    ${renderHeader(context, 'Benutzerverwaltung', 'Benutzerkonten anlegen, bearbeiten und deaktivieren.')}
    <div id="admin-message"></div>
    <div class="intern-card">
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
    </div>
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
    if (listEl) listEl.innerHTML = renderUserList(users, context.user!);
    bindUserActions(context, users, loadUsers, msgEl);
  };

  await loadUsers();

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
  bindHeaderLogout(context);
}

function renderUserList(users: AdminUser[], currentUser: PortalUser): string {
  if (!users.length) return '<div class="intern-empty">Keine Benutzer vorhanden.</div>';
  return `
    <table class="intern-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>E-Mail</th>
          <th>Rolle</th>
          <th>Status</th>
          <th>Letzter Login</th>
          <th>Aktionen</th>
        </tr>
      </thead>
      <tbody>
        ${users.map((u) => {
          const isSelf = String(u.id) === currentUser.id;
          const statusBadge = u.is_active
            ? '<span class="intern-badge intern-badge--ok">Aktiv</span>'
            : '<span class="intern-badge intern-badge--warn">Deaktiviert</span>';
          return `
            <tr data-user-id="${u.id}">
              <td><strong>${escapeHtml(u.full_name)}</strong></td>
              <td>${escapeHtml(u.email)}</td>
              <td>${escapeHtml(u.role)}</td>
              <td>${statusBadge}</td>
              <td class="intern-meta">${u.last_login_at ? formatDateTime(u.last_login_at) : '—'}</td>
              <td class="intern-actions intern-actions--inline">
                <button class="sv-button sv-button-secondary" type="button" data-edit-user="${u.id}">Bearbeiten</button>
                <button class="sv-button sv-button-secondary" type="button" data-pw-user="${u.id}">Passwort</button>
                ${!isSelf && u.is_active ? `<button class="sv-button sv-button-secondary" type="button" data-deactivate-user="${u.id}">Deaktivieren</button>` : ''}
              </td>
            </tr>
            <tr id="edit-row-${u.id}" class="intern-edit-row" hidden></tr>
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
      row.innerHTML = `
        <td colspan="6">
          <form class="intern-form-grid intern-edit-form" data-pw-form="${id}">
            <div class="intern-field">
              <label>Neues Passwort (mind. 10 Zeichen)</label>
              <input name="password" type="password" minlength="10" required autocomplete="new-password" />
            </div>
            <div class="intern-actions intern-field--full">
              <button class="sv-button sv-button-primary" type="submit">Passwort setzen</button>
            </div>
          </form>
        </td>
      `;
      row.querySelector<HTMLFormElement>(`[data-pw-form="${id}"]`)?.addEventListener('submit', async (evt) => {
        evt.preventDefault();
        const form = evt.currentTarget as HTMLFormElement;
        const password = String(new FormData(form).get('password') ?? '');
        const { error } = await apiSetUserPassword(id, password);
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
  return `
    <div class="intern-card intern-hero">
      <p class="sv-eyebrow">${escapeHtml(portalProject.title)}</p>
      <h1>${escapeHtml(title)}</h1>
      <p>${escapeHtml(text)}</p>
      <nav class="intern-actions" aria-label="Hauptnavigation">
        <a class="sv-button sv-button-secondary" href="/intern/fensterpruefung-bonn/">Dashboard</a>
        <a class="sv-button sv-button-secondary" href="/intern/fensterpruefung-bonn/gebaeude/">Gebäude</a>
        <a class="sv-button sv-button-secondary" href="/intern/fensterpruefung-bonn/auswertung/">Auswertung</a>
        <a class="sv-button sv-button-secondary" href="/intern/fensterpruefung-bonn/export/">Export</a>
        ${isAdmin ? '<a class="sv-button sv-button-secondary" href="/intern/fensterpruefung-bonn/admin/">Benutzerverwaltung</a>' : ''}
        <button class="sv-button sv-button-ghost" type="button" id="header-logout">Abmelden</button>
      </nav>
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
    <html lang="de"><head><title>Fensterbeschlagsprüfung BMVg Bonn – Sammelprotokoll</title><style>
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
    <p style="margin:4px 0 0;font-size:10pt;color:#555">Fensterbeschlagsprüfung BMVg Bonn</p>
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
    ? '<span class="intern-badge intern-badge--ok">Online · Verbunden</span>'
    : '<span class="intern-badge intern-badge--warn">Offline · Lokale Speicherung aktiv</span>';
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
