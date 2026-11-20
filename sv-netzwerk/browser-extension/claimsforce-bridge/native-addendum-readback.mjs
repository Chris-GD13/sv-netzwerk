import {NATIVE_PROFILES} from './native-field-contract.mjs';

const uuid = /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/;
const assessment = 'https://jjz5b23ffh.execute-api.eu-central-1.amazonaws.com/Prod';
const disposition = 'https://lrb5f35qpf.execute-api.eu-central-1.amazonaws.com/Prod';

function endpoint(value, expected) {
  const url = new URL(value);
  if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash
      || (url.href !== expected && !url.hostname.endsWith('.claimsforce.com'))) throw Error('Rücklesen: Claimsforce-Dienst ist nicht eindeutig.');
  return url.href.replace(/\/+$/, '');
}

function reportText(report) {
  const matches = [];
  for (const [section, spec] of Object.entries(report.template?.definition?.properties ?? {})) {
    for (const [field, detail] of Object.entries(spec.properties ?? {})) {
      const context = [section, spec.title, report.template?.name].filter(Boolean).join(' ');
      const name = [field, detail.title].filter(Boolean).join(' ');
      const value = report.content?.[section]?.[field];
      if (typeof value === 'string' && (/\b(?:nachtrag|stellungnahme|nachtragstext|nachtragsbericht|ergänzungsbericht)\b/i.test(name)
          || (/\b(?:nachtrag|stellungnahme|nachtragstext|nachtragsbericht|ergänzungsbericht)\b/i.test(context)
              && /\b(?:text|inhalt|berichtstext|freitext)\b/i.test(name)))) {
        matches.push(value);
      }
    }
  }
  if (matches.length !== 1) throw Error('Nachtragstext: Das native Nachtragsfeld ist nicht eindeutig.');
  return matches[0];
}

const fileVersion = file => [file.id, file.updatedDate || file.updatedAt || file.modifiedAt || file.createdDate || file.createdAt,
  file.sizeInBytes || file.size || file.fileSize, file.name || file.fileName || file.originalFilename].map(value=>String(value || '')).join('|');

export async function readNativeAddendum(request, io) {
  const started = performance.now();
  if (!NATIVE_PROFILES.includes(request.profile) || !uuid.test(request.caseId) || !uuid.test(request.reportId)
      || !uuid.test(request.operationId) || !request.claimNumber
      || Object.entries(request.forms ?? {}).some(([kind,id])=>!['SE','ZB'].includes(kind) || !uuid.test(id))) {
    throw Error('Nachtrag: Browserauftrag enthält eine ungültige Zuordnung.');
  }
  await io.assertAccount(request.profile);
  const config = await io.config();
  const path = '/claims/'+request.caseId;
  const [claimResponse, reportResponse] = await Promise.all([
    io.json(endpoint(config.DISPOSITION_API_ENDPOINT, disposition)+path),
    io.json(endpoint(config.ASSESSMENT_API_ENDPOINT, assessment)+path+'/investigations')
  ]);
  const claim = claimResponse?.claim ?? claimResponse;
  if (claim?.id !== request.caseId || claim.insurerClaimId !== request.claimNumber) throw Error('Schadennummer: Claimsforce-Fall stimmt nicht mit dem Auftrag überein.');
  // The native client reopens a report using the claim-scoped assessment list.
  // Only the selected report and the evidence attached to that report leave this worker.
  const reports = reportResponse?.investigations?.filter(report=>report.id===request.reportId);
  if (reports?.length !== 1 || (reports[0].claimId && reports[0].claimId !== claim.id)) throw Error('Nachtrag: Entwurf im zugeordneten Fall fehlt.');
  const report = reports[0];
  if (report.status !== 'IN_PROGRESS' || report.isLocked !== false) throw Error('Nachtrag: gespeicherte Fassung ist kein editierbarer Entwurf.');
  const text = reportText(report);
  if (!Array.isArray(report.evidence) || report.evidence.some(file=>!file || typeof file.id!=='string')) {
    throw Error('Anlagen: gespeicherte Auswahl konnte nicht eindeutig gelesen werden.');
  }
  const linked = report.evidence.filter(file=>['Document','Photo'].includes(file.type));
  if (report.evidence.some(file=>!['Document','Photo','Calculation'].includes(file.type))) throw Error('Anlagen: unbekannter Anlagentyp.');
  const attachments = report.evidence.filter(file=>file.type==='Calculation')
    .map(file=>({id:file.id,name:'Claimsforce-Kalkulation',version:null}));
  if (linked.length) {
    const response = await io.json(endpoint(config.FILES_API_ENDPOINT)+path+'/files');
    if (!Array.isArray(response?.files)) throw Error('Anlagen: Dateifassungen konnten nicht gelesen werden.');
    attachments.push(...linked.map(link=>{
      const files = response.files.filter(file=>file.id===link.id && (!file.claimId || file.claimId===claim.id));
      const file = files.length===1 ? files[0] : null;
      return {id:link.id,name:file?.fileName || file?.name || link.id,version:file ? fileVersion(file) : null};
    }));
  }
  const forms = Object.keys(request.forms ?? {}).length ? await io.readForms(request) : {};
  await io.assertAccount(request.profile);
  return {source:'claimsforce-native-addendum', readOnly:true, operationId:request.operationId,
    caseId:claim.id, claimNumber:claim.insurerClaimId, profile:request.profile,
    report:{id:report.id, status:'draft', text, attachments}, forms, durationMs:Math.round(performance.now()-started)};
}
