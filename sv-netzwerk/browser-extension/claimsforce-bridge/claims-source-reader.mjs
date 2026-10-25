const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;
export function parseSourceRequest(key) {
  const [claimId, kind, sourceId = '', ...extra] = String(key).split(':');
  if (extra.length || !uuid.test(claimId) || !['messages','message','document'].includes(kind) || (kind === 'messages' ? sourceId !== '' : !uuid.test(sourceId))) throw Error('SOURCE_READ_REQUEST_INVALID');
  return {claimId, kind, sourceId};
}

// Injected readers perform authenticated GETs only. No case, task, note or file
// is changed. Membership is checked before a detailed message/document read.
export async function readClaimsSource(key, {profile, json, bytes, config}) {
  const request = parseSourceRequest(key), {claimId, kind, sourceId} = request;
  if (!['christian','jens','marc','holger'].includes(profile)) throw Error('SOURCE_PROFILE_REQUIRED');
  for (const k of ['DISPOSITION_API_ENDPOINT','COMMUNICATION_API_ENDPOINT','FILES_API_ENDPOINT']) {
    const url = new URL(config[k]);
    if (url.protocol !== 'https:' || !url.hostname.endsWith('.claimsforce.com') || url.username || url.password) throw Error('SOURCE_ENDPOINT_INVALID');
  }
  const unwrap = (value, name) => value?.[name] ?? value;
  const claim = unwrap(await json(`${config.DISPOSITION_API_ENDPOINT}/claims/${claimId}`), 'claim');
  if (claim?.id !== claimId) throw Error('SOURCE_CLAIM_IDENTITY_MISMATCH');
  const messages = unwrap(await json(`${config.COMMUNICATION_API_ENDPOINT}/claims/${claimId}/messages`), 'messages');
  if (!Array.isArray(messages)) throw Error('SOURCE_MESSAGES_SHAPE_INVALID');
  const result = {source:'claimsforce-source-read', sourceProfile:profile, ...request, fetchedAt:new Date().toISOString(), readOnly:true};
  if (kind === 'messages') {
    return {...result, messages:messages.map(m=>({id:m.id, subject:m.subject || m.payload?.subject || '', createdAt:m.createdAt || m.createdDate || '', sentAt:m.sentAt || '', updatedAt:m.updatedAt || m.updatedDate || '', attachments:m.attachments || []}))};
  }
  if (kind === 'message') {
    if (!messages.some(m=>m.id === sourceId)) throw Error('SOURCE_MESSAGE_NOT_IN_CLAIM');
    const message = unwrap(await json(`${config.COMMUNICATION_API_ENDPOINT}/claims/${claimId}/messages/${sourceId}`), 'message');
    if (message?.id !== sourceId || (message.claimId && message.claimId !== claimId)) throw Error('SOURCE_MESSAGE_IDENTITY_MISMATCH');
    return {...result, message};
  }
  const files = unwrap(await json(`${config.FILES_API_ENDPOINT}/claims/${claimId}/files`), 'files');
  if (!Array.isArray(files)) throw Error('SOURCE_FILES_SHAPE_INVALID');
  const file = files.find(f=>f.id===sourceId);
  const reference = messages.some(m=>(m.attachments || []).some(a=>a.type==='CLAIM_FILE' && a.id===sourceId));
  if (!file && !reference) throw Error('SOURCE_FILE_NOT_IN_CLAIM');
  const document = await bytes(`${config.FILES_API_ENDPOINT}/claims/${claimId}/files/${sourceId}`);
  return {...result, name:file?.name || file?.fileName || sourceId, ...document};
}
