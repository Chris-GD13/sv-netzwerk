import type { OfflineDraft } from './types';

export interface SyncQueueItem {
  id: string;
  windowId: string;
  action: 'save' | 'delete';
  data: Record<string, unknown>;
  calculatedData: Record<string, unknown>;
  timestamp: number;
  retries: number;
  lastError?: string;
}

export interface SyncState {
  isOnline: boolean;
  isSyncing: boolean;
  lastSyncTime: number | null;
  pendingCount: number;
  failedCount: number;
}

const DB_NAME = 'sv-intern-offline';
const QUEUE_STORE = 'sync-queue';
const STATE_STORE = 'sync-state';

function openDatabase(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 2);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(QUEUE_STORE)) {
        db.createObjectStore(QUEUE_STORE, { keyPath: 'id' });
      }
      if (!db.objectStoreNames.contains(STATE_STORE)) {
        db.createObjectStore(STATE_STORE, { keyPath: 'key' });
      }
    };
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
  });
}

export async function enqueueSync(item: Omit<SyncQueueItem, 'id' | 'retries' | 'timestamp'>): Promise<string> {
  const db = await openDatabase();
  const id = `${item.windowId}-${Date.now()}`;
  const queueItem: SyncQueueItem = {
    ...item,
    id,
    timestamp: Date.now(),
    retries: 0,
  };

  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(QUEUE_STORE, 'readwrite');
    tx.objectStore(QUEUE_STORE).add(queueItem);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
  db.close();
  return id;
}

export async function getQueueItems(): Promise<SyncQueueItem[]> {
  const db = await openDatabase();
  const result = await new Promise<SyncQueueItem[]>((resolve, reject) => {
    const tx = db.transaction(QUEUE_STORE, 'readonly');
    const request = tx.objectStore(QUEUE_STORE).getAll();
    request.onsuccess = () => resolve((request.result as SyncQueueItem[]) ?? []);
    request.onerror = () => reject(request.error);
  });
  db.close();
  return result;
}

export async function removeQueueItem(id: string): Promise<void> {
  const db = await openDatabase();
  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(QUEUE_STORE, 'readwrite');
    tx.objectStore(QUEUE_STORE).delete(id);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
  db.close();
}

export async function updateQueueItem(id: string, updates: Partial<SyncQueueItem>): Promise<void> {
  const db = await openDatabase();
  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(QUEUE_STORE, 'readwrite');
    const store = tx.objectStore(QUEUE_STORE);
    const request = store.get(id);

    request.onsuccess = () => {
      const item = request.result as SyncQueueItem | undefined;
      if (item) {
        store.put({ ...item, ...updates });
      }
    };

    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
  db.close();
}

export async function getSyncState(): Promise<SyncState> {
  const db = await openDatabase();
  const result = await new Promise<Partial<SyncState> | null>((resolve, reject) => {
    const tx = db.transaction(STATE_STORE, 'readonly');
    const request = tx.objectStore(STATE_STORE).get('sync-state');
    request.onsuccess = () => resolve((request.result as Partial<SyncState> | undefined) ?? null);
    request.onerror = () => reject(request.error);
  });
  db.close();

  return {
    isOnline: result?.isOnline ?? navigator.onLine,
    isSyncing: result?.isSyncing ?? false,
    lastSyncTime: result?.lastSyncTime ?? null,
    pendingCount: result?.pendingCount ?? 0,
    failedCount: result?.failedCount ?? 0,
  };
}

export async function updateSyncState(updates: Partial<SyncState>): Promise<void> {
  const db = await openDatabase();
  const current = await getSyncState();
  const newState = { ...current, ...updates, key: 'sync-state' };

  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(STATE_STORE, 'readwrite');
    tx.objectStore(STATE_STORE).put(newState);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
  db.close();
}

export async function clearSyncQueue(): Promise<void> {
  const db = await openDatabase();
  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(QUEUE_STORE, 'readwrite');
    tx.objectStore(QUEUE_STORE).clear();
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
  db.close();
}

export type SyncCallback = (state: SyncState) => void;

const syncListeners: Set<SyncCallback> = new Set();

export function onSyncStateChange(callback: SyncCallback): () => void {
  syncListeners.add(callback);
  return () => syncListeners.delete(callback);
}

export async function notifySyncStateChange(): Promise<void> {
  const state = await getSyncState();
  syncListeners.forEach(cb => cb(state));
}
