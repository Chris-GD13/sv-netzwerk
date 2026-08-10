/**
 * Window Template Management
 * Stores and retrieves window property templates (manufacturer, window type, hardware, etc.)
 * for reuse within a project to speed up data entry.
 */

import type { WindowTemplate } from './types';

const DB_NAME = 'sv-netzwerk-portal';
const STORE_NAME = 'window_templates';
const DB_VERSION = 1;

let db: IDBDatabase | null = null;

async function getDB(): Promise<IDBDatabase> {
  if (db) return db;

  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      db = request.result;
      resolve(db);
    };

    request.onupgradeneeded = (event) => {
      const database = (event.target as IDBOpenDBRequest).result;
      if (!database.objectStoreNames.contains(STORE_NAME)) {
        const store = database.createObjectStore(STORE_NAME, { keyPath: 'id' });
        store.createIndex('projectId', 'projectId', { unique: false });
        store.createIndex('projectId_name', ['projectId', 'name'], { unique: true });
      }
    };
  });
}

export async function saveTemplate(template: Omit<WindowTemplate, 'id' | 'createdAt'>): Promise<WindowTemplate> {
  const database = await getDB();
  const fullTemplate: WindowTemplate = {
    ...template,
    id: `${template.projectId}_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`,
    createdAt: new Date().toISOString(),
  };

  return new Promise((resolve, reject) => {
    const transaction = database.transaction([STORE_NAME], 'readwrite');
    const store = transaction.objectStore(STORE_NAME);
    const request = store.add(fullTemplate);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(fullTemplate);
  });
}

export async function loadTemplates(projectId: string): Promise<WindowTemplate[]> {
  const database = await getDB();

  return new Promise((resolve, reject) => {
    const transaction = database.transaction([STORE_NAME], 'readonly');
    const store = transaction.objectStore(STORE_NAME);
    const index = store.index('projectId');
    const request = index.getAll(projectId);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const templates = (request.result as WindowTemplate[]).sort(
        (a, b) => new Date(b.lastUsed || b.createdAt).getTime() - new Date(a.lastUsed || a.createdAt).getTime()
      );
      resolve(templates);
    };
  });
}

export async function updateTemplate(templateId: string, updates: Partial<WindowTemplate>): Promise<WindowTemplate> {
  const database = await getDB();

  return new Promise((resolve, reject) => {
    const transaction = database.transaction([STORE_NAME], 'readwrite');
    const store = transaction.objectStore(STORE_NAME);
    const getRequest = store.get(templateId);

    getRequest.onerror = () => reject(getRequest.error);
    getRequest.onsuccess = () => {
      const template = getRequest.result as WindowTemplate;
      const updated = { ...template, ...updates };
      const putRequest = store.put(updated);

      putRequest.onerror = () => reject(putRequest.error);
      putRequest.onsuccess = () => resolve(updated);
    };
  });
}

export async function deleteTemplate(templateId: string): Promise<void> {
  const database = await getDB();

  return new Promise((resolve, reject) => {
    const transaction = database.transaction([STORE_NAME], 'readwrite');
    const store = transaction.objectStore(STORE_NAME);
    const request = store.delete(templateId);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve();
  });
}

export async function markTemplateUsed(templateId: string): Promise<void> {
  const database = await getDB();

  return new Promise((resolve, reject) => {
    const transaction = database.transaction([STORE_NAME], 'readwrite');
    const store = transaction.objectStore(STORE_NAME);
    const getRequest = store.get(templateId);

    getRequest.onerror = () => reject(getRequest.error);
    getRequest.onsuccess = () => {
      const template = getRequest.result as WindowTemplate;
      const updated = {
        ...template,
        lastUsed: new Date().toISOString(),
        usageCount: (template.usageCount || 0) + 1,
      };
      const putRequest = store.put(updated);

      putRequest.onerror = () => reject(putRequest.error);
      putRequest.onsuccess = () => resolve();
    };
  });
}
