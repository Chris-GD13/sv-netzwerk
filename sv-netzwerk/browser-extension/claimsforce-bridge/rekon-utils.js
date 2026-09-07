const text = value => value == null ? '' : String(value).trim();

export const REKON_PROFILE_NAMES = {
  marc: ['marc', 'schütt', 'schuett'],
  holger: ['holger', 'roth']
};

export function rekonProfileKey(value) {
  const profile = text(value).toLowerCase();
  if (!Object.hasOwn(REKON_PROFILE_NAMES, profile)) throw new Error('Rekon-Import ist nur für Marc oder Holger freigegeben.');
  return profile;
}

export function ownerMatchesRekonProfile(ownerName, profile) {
  const normalized = text(ownerName).toLowerCase().replace(/ü/g, 'ue').replace(/[^a-z0-9]+/g, ' ');
  const expected = REKON_PROFILE_NAMES[rekonProfileKey(profile)].map(value => value.replace(/ü/g, 'ue'));
  return expected.every(value => normalized.includes(value));
}

export function isActiveRekonTask(task) {
  const state = text(task?.state?.title || task?.state_title).toLowerCase();
  return !!task?.id && !/(abgeschlossen|storniert|abgelehnt|gelöscht|geloescht|cancelled|completed)/i.test(state);
}

export function mapRekonTask(task) {
  const location = task?.primary_location || {};
  const customer = task?.customer || {};
  const appointment = task?.appointment || null;
  return {
    schaden_nr: text(task?.identifier || task?.external_number || task?.id),
    versicherungsschein_nr: text(task?.policy_number),
    vn_objekt: text(customer.full_name || [customer.first_name, customer.name].filter(Boolean).join(' ') || task?.claimant?.name),
    schaden_strasse: [location.street, location.street_no].map(text).filter(Boolean).join(' '),
    schaden_plz: text(location.postcode),
    schaden_ort: text(location.city),
    schadenart: text(task?.primary_form?.template?.title || task?.visit_type?.title),
    reserve: text(task?.reserve),
    telefon: text(customer.phone || customer.phone2),
    mobil: text(customer.mobile || customer.mobile2),
    email: text(customer.email || customer.email2),
    rekon_task_id: text(task?.id),
    rekon_status: text(task?.state?.title),
    rekon_owner: text(task?.owner?.name),
    rekon_termin: appointment ? {
      id: text(appointment.id),
      startDate: text(appointment.date_from),
      endDate: text(appointment.date_to),
      comment: text(appointment.description)
    } : null,
    rekon_quelle: `https://www.rekoninterschaden-portal.de/tasks/${encodeURIComponent(text(task?.id))}/common`,
    rekon_zuletzt_eingelesen: new Date().toISOString()
  };
}

export const rekonFileVersion = file => [file?.id, file?.updated_at || file?.created_at, file?.size, file?.name || file?.original_file_name].map(text).join('|');
export const rekonMessageVersion = email => [email?.id, email?.send_date, email?.subject, ...(email?.attachments || []).map(item => rekonFileVersion(item?.file))].map(text).join('|');
