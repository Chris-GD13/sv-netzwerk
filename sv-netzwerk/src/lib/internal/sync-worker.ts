import { enqueueSync, getQueueItems, removeQueueItem, updateQueueItem, getSyncState, updateSyncState, notifySyncStateChange } from './sync';
import { apiSaveWindow, apiDeleteWindow } from './php-api';

const MAX_RETRIES = 3;
const SYNC_INTERVAL = 5000;
let syncTimer: NodeJS.Timeout | null = null;
let isSyncing = false;

export async function initializeSyncWorker(): Promise<void> {
  // Online/Offline listeners
  window.addEventListener('online', () => handleOnline());
  window.addEventListener('offline', () => handleOffline());

  // Initial state
  await updateSyncState({ isOnline: navigator.onLine });
  await notifySyncStateChange();

  // Start sync loop
  startSyncWorker();
}

async function handleOnline(): Promise<void> {
  await updateSyncState({ isOnline: true });
  await notifySyncStateChange();

  // Trigger immediate sync
  await performSync();
}

async function handleOffline(): Promise<void> {
  await updateSyncState({ isOnline: false });
  await notifySyncStateChange();

  // Stop active sync
  stopSyncWorker();
}

function startSyncWorker(): void {
  if (syncTimer) return;

  syncTimer = setInterval(() => {
    if (navigator.onLine && !isSyncing) {
      performSync().catch(err => console.error('[Sync Worker] Error:', err));
    }
  }, SYNC_INTERVAL);
}

function stopSyncWorker(): void {
  if (syncTimer) {
    clearInterval(syncTimer);
    syncTimer = null;
  }
}

export async function performSync(): Promise<void> {
  if (isSyncing) return;

  isSyncing = true;
  await updateSyncState({ isSyncing: true });
  await notifySyncStateChange();

  try {
    const items = await getQueueItems();
    let successCount = 0;
    let failureCount = 0;

    for (const item of items) {
      try {
        if (item.action === 'save') {
          await apiSaveWindow(item.windowId, item.data, item.calculatedData);
        } else if (item.action === 'delete') {
          const windowId = typeof item.windowId === 'string' ? parseInt(item.windowId, 10) : item.windowId;
          await apiDeleteWindow(windowId);
        }

        // Success - remove from queue
        await removeQueueItem(item.id);
        successCount++;
      } catch (error) {
        // Increment retries
        const newRetries = item.retries + 1;

        if (newRetries >= MAX_RETRIES) {
          // Too many retries - mark as failed
          failureCount++;
          await updateQueueItem(item.id, {
            retries: newRetries,
            lastError: String(error),
          });
        } else {
          // Retry later
          await updateQueueItem(item.id, {
            retries: newRetries,
            lastError: String(error),
          });
        }
      }
    }

    // Update sync state
    const remainingItems = await getQueueItems();
    await updateSyncState({
      lastSyncTime: Date.now(),
      pendingCount: remainingItems.filter(i => i.retries < MAX_RETRIES).length,
      failedCount: remainingItems.filter(i => i.retries >= MAX_RETRIES).length,
    });

    console.log(`[Sync] Synced ${successCount} items, ${failureCount} failed`);
  } finally {
    isSyncing = false;
    await updateSyncState({ isSyncing: false });
    await notifySyncStateChange();
  }
}

export async function queueWindowSave(
  windowId: string,
  data: Record<string, unknown>,
  calculatedData: Record<string, unknown>
): Promise<string> {
  const id = await enqueueSync({
    windowId,
    action: 'save',
    data,
    calculatedData,
  });

  // Update pending count
  const items = await getQueueItems();
  await updateSyncState({ pendingCount: items.length });
  await notifySyncStateChange();

  return id;
}

export async function queueWindowDelete(windowId: string): Promise<string> {
  const id = await enqueueSync({
    windowId,
    action: 'delete',
    data: {},
    calculatedData: {},
  });

  // Update pending count
  const items = await getQueueItems();
  await updateSyncState({ pendingCount: items.length });
  await notifySyncStateChange();

  return id;
}

export async function getOfflineStatus() {
  const state = await getSyncState();
  const items = await getQueueItems();

  return {
    ...state,
    items,
  };
}

export { stopSyncWorker };
