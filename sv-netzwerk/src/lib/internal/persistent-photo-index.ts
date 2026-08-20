/**
 * Persistent SharePoint photo-analysis index.
 *
 * SharePoint remains the image master. This module stores only durable
 * analysis metadata/results so an interrupted run can resume instead of
 * downloading and OCR-processing every image again.
 *
 * Browser persistence is intentionally used as a zero-backend migration
 * step. The same record shape can be moved to the project database later
 * without changing the analysis workflow.
 */

export type PhotoIndexStatus = 'pending' | 'processing' | 'done' | 'error';

export interface PersistentPhotoIndexRecord {
  projectId: string;
  sourceId: string;
  sourcePath?: string;
  fileName: string;
  modifiedAt?: string;
  size?: number;
  fingerprint: string;
  status: PhotoIndexStatus;
  category?: string;
  strikeNumber?: string | null;
  targetWindowId?: string | number | null;
  targetFluegelId?: string | number | null;
  confidence?: number | null;
  analysis?: unknown;
  error?: string | null;
  analyzedAt?: string | null;
  updatedAt: string;
}

export interface PhotoSourceDescriptor {
  projectId: string;
  sourceId: string;
  sourcePath?: string;
  fileName: string;
  modifiedAt?: string;
  size?: number;
}

const DB_NAME = 'sv-netzwerk-photo-index';
const DB_VERSION = 1;
const STORE = 'photos';

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE)) {
        const store = db.createObjectStore(STORE, { keyPath: ['projectId', 'sourceId'] });
        store.createIndex('projectId', 'projectId', { unique: false });
        store.createIndex('status', 'status', { unique: false });
      }
    };
  });
}

export function photoFingerprint(source: PhotoSourceDescriptor): string {
  return [source.sourceId, source.modifiedAt || '', source.size ?? '', source.fileName].join('|');
}

export async function getPhotoIndexRecord(
  projectId: string,
  sourceId: string,
): Promise<PersistentPhotoIndexRecord | null> {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readonly');
    const request = tx.objectStore(STORE).get([projectId, sourceId]);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve((request.result as PersistentPhotoIndexRecord) || null);
    tx.oncomplete = () => db.close();
  });
}

export async function putPhotoIndexRecord(record: PersistentPhotoIndexRecord): Promise<void> {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).put(record);
    tx.onerror = () => reject(tx.error);
    tx.oncomplete = () => {
      db.close();
      resolve();
    };
  });
}

export async function shouldAnalyzePhoto(
  source: PhotoSourceDescriptor,
  force = false,
): Promise<{ analyze: boolean; cached: PersistentPhotoIndexRecord | null }> {
  const cached = await getPhotoIndexRecord(source.projectId, source.sourceId);
  if (force || !cached) return { analyze: true, cached };

  const unchanged = cached.fingerprint === photoFingerprint(source);
  if (unchanged && cached.status === 'done') return { analyze: false, cached };

  // A browser/session crash can leave a record in `processing`. Re-run only
  // that unfinished image; all preceding `done` records remain checkpoints.
  return { analyze: true, cached };
}

export async function markPhotoProcessing(source: PhotoSourceDescriptor): Promise<void> {
  await putPhotoIndexRecord({
    projectId: source.projectId,
    sourceId: source.sourceId,
    sourcePath: source.sourcePath,
    fileName: source.fileName,
    modifiedAt: source.modifiedAt,
    size: source.size,
    fingerprint: photoFingerprint(source),
    status: 'processing',
    updatedAt: new Date().toISOString(),
  });
}

export async function markPhotoDone(
  source: PhotoSourceDescriptor,
  result: Partial<Omit<PersistentPhotoIndexRecord, keyof PhotoSourceDescriptor | 'fingerprint' | 'status' | 'updatedAt'>>,
): Promise<void> {
  await putPhotoIndexRecord({
    projectId: source.projectId,
    sourceId: source.sourceId,
    sourcePath: source.sourcePath,
    fileName: source.fileName,
    modifiedAt: source.modifiedAt,
    size: source.size,
    fingerprint: photoFingerprint(source),
    status: 'done',
    ...result,
    error: null,
    analyzedAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  });
}

export async function markPhotoError(source: PhotoSourceDescriptor, error: unknown): Promise<void> {
  await putPhotoIndexRecord({
    projectId: source.projectId,
    sourceId: source.sourceId,
    sourcePath: source.sourcePath,
    fileName: source.fileName,
    modifiedAt: source.modifiedAt,
    size: source.size,
    fingerprint: photoFingerprint(source),
    status: 'error',
    error: error instanceof Error ? error.message : String(error),
    updatedAt: new Date().toISOString(),
  });
}

export async function listProjectPhotoIndex(projectId: string): Promise<PersistentPhotoIndexRecord[]> {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readonly');
    const request = tx.objectStore(STORE).index('projectId').getAll(projectId);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve((request.result as PersistentPhotoIndexRecord[]) || []);
    tx.oncomplete = () => db.close();
  });
}

/**
 * Generic resumable runner. `load` is called only for photos that do not have
 * an unchanged successful checkpoint, so SharePoint downloads are skipped for
 * cached photos. `analyze` receives the loaded image and persists its result
 * immediately after each successful image.
 */
export async function runResumablePhotoAnalysis<TImage, TResult>(options: {
  sources: PhotoSourceDescriptor[];
  force?: boolean;
  load: (source: PhotoSourceDescriptor) => Promise<TImage>;
  analyze: (image: TImage, source: PhotoSourceDescriptor) => Promise<TResult>;
  toIndexResult?: (result: TResult) => Partial<PersistentPhotoIndexRecord>;
  onProgress?: (progress: { processed: number; total: number; cached: number; source: PhotoSourceDescriptor }) => void;
}): Promise<{ processed: number; cached: number; total: number }> {
  let processed = 0;
  let cachedCount = 0;

  for (const source of options.sources) {
    const decision = await shouldAnalyzePhoto(source, options.force === true);
    if (!decision.analyze) {
      cachedCount += 1;
      options.onProgress?.({ processed, total: options.sources.length, cached: cachedCount, source });
      continue;
    }

    await markPhotoProcessing(source);
    try {
      const image = await options.load(source);
      const result = await options.analyze(image, source);
      await markPhotoDone(source, options.toIndexResult?.(result) || { analysis: result });
      processed += 1;
      options.onProgress?.({ processed, total: options.sources.length, cached: cachedCount, source });
    } catch (error) {
      await markPhotoError(source, error);
      throw error;
    }
  }

  return { processed, cached: cachedCount, total: options.sources.length };
}
