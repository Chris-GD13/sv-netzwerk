export function isClaimsforceMail(item) {
  return /^Mail_ClaimsForce-Nachricht_/i.test(item.name || '') && (item.mimeType === 'application/json' || /\.json$/i.test(item.name || ''));
}

export function normalizeClaimsforceMail(record, textFromHtml = value => String(value || '')) {
  const payload = record.payload || record;
  const address = value => {
    if (typeof value === 'string') return value;
    const name = value?.name || '', email = value?.email || value?.address || '';
    return name && email ? `${name} <${email}>` : name || email;
  };
  const addresses = value => (Array.isArray(value) ? value : value ? [value] : []).map(address).join('; ');
  const imported = record._svnetImport?.attachments || [];
  return {
    subject: payload.subject || '(ohne Betreff)', from: address(payload.from), to: addresses(payload.to), cc: addresses(payload.cc),
    date: record.sentAt || record.createdDate || record.createdAt,
    body: textFromHtml(payload.body || payload.text || ''),
    attachments: (record.attachments || []).map(attachment => {
      const source = imported.find(file => file.id === attachment.id) || {};
      return { name: source.name || attachment.fileName || attachment.name || `Falldokument ${attachment.id}`, reference: true,
        status: source.restricted ? 'In ClaimsForce gesperrt; nur der Verweis ist verfügbar.' : 'Im Dokumentenbestand dieses Falls abgelegt.', path: source.path || '' };
    })
  };
}
