/** Persistenter SharePoint-Fotoindex: SharePoint bleibt Master; OCR-Ergebnisse werden lokal dauerhaft gecacht. */
export type PhotoIndexStatus = 'processing' | 'done' | 'error';
export interface PhotoIndexSource { projectId:string; sourceId:string; fileName:string; size?:number; modifiedAt?:string; }
export interface PhotoIndexRecord extends PhotoIndexSource { fingerprint:string; status:PhotoIndexStatus; strikeNumber?:string; error?:string|null; updatedAt:string; }
const DB='sv-netzwerk-photo-index', STORE='photos';
function openDb():Promise<IDBDatabase>{return new Promise((resolve,reject)=>{const r=indexedDB.open(DB,1);r.onerror=()=>reject(r.error);r.onsuccess=()=>resolve(r.result);r.onupgradeneeded=()=>{const db=r.result;if(!db.objectStoreNames.contains(STORE)){const s=db.createObjectStore(STORE,{keyPath:['projectId','sourceId']});s.createIndex('projectId','projectId',{unique:false});}};});}
export function photoFingerprint(s:PhotoIndexSource){return [s.sourceId,s.modifiedAt||'',s.size??'',s.fileName].join('|');}
export async function getPhotoCheckpoint(s:PhotoIndexSource):Promise<PhotoIndexRecord|null>{const db=await openDb();return new Promise((resolve,reject)=>{const tx=db.transaction(STORE,'readonly'),r=tx.objectStore(STORE).get([s.projectId,s.sourceId]);r.onerror=()=>reject(r.error);r.onsuccess=()=>resolve(r.result||null);tx.oncomplete=()=>db.close();});}
export async function putPhotoCheckpoint(r:PhotoIndexRecord):Promise<void>{const db=await openDb();return new Promise((resolve,reject)=>{const tx=db.transaction(STORE,'readwrite');tx.objectStore(STORE).put(r);tx.onerror=()=>reject(tx.error);tx.oncomplete=()=>{db.close();resolve();};});}
export async function cachedStrikeNumber(s:PhotoIndexSource):Promise<string|null>{const r=await getPhotoCheckpoint(s);return r&&r.status==='done'&&r.fingerprint===photoFingerprint(s)?(r.strikeNumber??''):null;}
export async function markPhotoDone(s:PhotoIndexSource,strikeNumber:string){await putPhotoCheckpoint({...s,fingerprint:photoFingerprint(s),status:'done',strikeNumber,error:null,updatedAt:new Date().toISOString()});}
export async function markPhotoError(s:PhotoIndexSource,error:unknown){await putPhotoCheckpoint({...s,fingerprint:photoFingerprint(s),status:'error',error:error instanceof Error?error.message:String(error),updatedAt:new Date().toISOString()});}
