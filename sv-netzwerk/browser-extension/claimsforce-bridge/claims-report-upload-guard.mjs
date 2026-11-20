// Unwired safety coordinator. No Claimsforce transport or production adapter is supplied.
// The adapter must serialize all case writes and durably journal before transport.
const UUID = /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i;
const SHA = /^[a-f0-9]{64}$/;
const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
const LIMIT = 15 * 1024 * 1024;
const hex = bytes => Array.from(new Uint8Array(bytes), x => x.toString(16).padStart(2, '0')).join('');
export async function sha256(bytes) { return hex(await crypto.subtle.digest('SHA-256', bytes)); }
function valid(c, bytes) {
  if (!c || c.profile !== 'christian' || !UUID.test(c.operationId || '') || !UUID.test(c.caseId || '') ||
      typeof c.claimNumber !== 'string' || !c.claimNumber.trim() || typeof c.folderId !== 'string' || !c.folderId.trim() ||
      typeof c.fileName !== 'string' || !/\.docx$/i.test(c.fileName) || /[\\/\x00-\x1f]/.test(c.fileName) ||
      c.mime !== MIME || !SHA.test(c.sha256 || '') || !(bytes instanceof Uint8Array) || bytes.length < 4 || bytes.length > LIMIT ||
      bytes[0] !== 0x50 || bytes[1] !== 0x4b || bytes[2] !== 0x03 || bytes[3] !== 0x04) throw Error('UPLOAD_INVALID_REQUEST');
}
function identity(c, snapshot) {
  if (!snapshot || snapshot.profile !== c.profile || snapshot.caseId !== c.caseId || snapshot.claimNumber !== c.claimNumber ||
      snapshot.folderId !== c.folderId || snapshot.folderLabel !== 'Berichte' || snapshot.path !== 'Dateien/Berichte' ||
      snapshot.complete !== true || !Array.isArray(snapshot.files)) throw Error('UPLOAD_IDENTITY_OR_LIST_UNVERIFIED');
}
function commandKey(c) { return JSON.stringify([c.profile,c.operationId,c.caseId,c.claimNumber,c.folderId,c.fileName,c.mime,c.sha256]); }
export async function uploadReportGuarded(c, bytes, io) {
  valid(c, bytes);
  if (await sha256(bytes) !== c.sha256) throw Error('UPLOAD_SOURCE_HASH_MISMATCH');
  if (!io?.withCaseLock || !io?.inspect || !io?.assertAccount || !io?.uploadNew || !io?.readBack || !io?.journal?.get || !io?.journal?.set)
    throw Error('UPLOAD_ADAPTER_INCOMPLETE');
  const key = commandKey(c);
  return io.withCaseLock(c.profile, c.caseId, async () => {
    const prior = await io.journal.get(c.operationId);
    if (prior) {
      if (prior.key !== key) throw Error('UPLOAD_OPERATION_ID_CONFLICT');
      // No blind retry, even if the previous upload may not have happened.
      if (prior.state !== 'verified') throw Error('UPLOAD_UNCERTAIN_MANUAL_RECONCILIATION');
      await io.assertAccount(c.profile);
      const snapshot = await io.inspect(c); identity(c, snapshot);
      const matches = snapshot.files.filter(f => f.id === prior.fileId && f.name === c.fileName);
      if (matches.length !== 1) throw Error('UPLOAD_PRIOR_FILE_NOT_FOUND');
      const restored = await io.readBack(c, prior.fileId);
      if (!(restored instanceof Uint8Array) || await sha256(restored) !== c.sha256) throw Error('UPLOAD_READBACK_MISMATCH');
      await io.assertAccount(c.profile);
      return {verified:true, reconciled:true, operationId:c.operationId, fileId:prior.fileId, sha256:c.sha256};
    }
    await io.assertAccount(c.profile);
    const before = await io.inspect(c); identity(c, before);
    // Name conflicts always require review; never replace or infer ownership.
    if (before.files.some(f => typeof f.name === 'string' && f.name.toLocaleLowerCase('de-DE') === c.fileName.toLocaleLowerCase('de-DE')))
      throw Error('UPLOAD_FILENAME_CONFLICT');
    // Durable marker before the first possible external mutation.
    await io.journal.set(c.operationId, {key,state:'uncertain'});
    await io.assertAccount(c.profile);
    const check = await io.inspect(c); identity(c, check);
    if (check.files.some(f => typeof f.name === 'string' && f.name.toLocaleLowerCase('de-DE') === c.fileName.toLocaleLowerCase('de-DE')))
      throw Error('UPLOAD_FILENAME_CONFLICT_BEFORE_WRITE');
    const fileId = await io.uploadNew(c, bytes); // adapter must create only; never overwrite
    if (typeof fileId !== 'string' || !fileId) throw Error('UPLOAD_NO_FILE_ID');
    await io.assertAccount(c.profile);
    const after = await io.inspect(c); identity(c, after);
    if (after.files.filter(f => f.id === fileId && f.name === c.fileName).length !== 1) throw Error('UPLOAD_DESTINATION_READBACK_MISMATCH');
    const restored = await io.readBack(c, fileId);
    if (!(restored instanceof Uint8Array) || await sha256(restored) !== c.sha256) throw Error('UPLOAD_READBACK_MISMATCH');
    await io.assertAccount(c.profile);
    await io.journal.set(c.operationId, {key,state:'verified',fileId});
    return {verified:true, reconciled:false, operationId:c.operationId, fileId, sha256:c.sha256};
  });
}
