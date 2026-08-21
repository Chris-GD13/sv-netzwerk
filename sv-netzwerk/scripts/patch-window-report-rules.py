from pathlib import Path

path = Path(__file__).resolve().parents[1] / 'src/lib/internal/client.ts'
source = path.read_text(encoding='utf-8')

start_marker = 'function buildAssessmentReport('
end_marker = '\nfunction wrapWordDocument('
if start_marker not in source or end_marker not in source:
    raise SystemExit('Gutachtengenerator nicht gefunden')

start = source.index(start_marker)
end = source.index(end_marker, start)

replacement = r'''function buildAssessmentReport(
  items: Array<{ record: WindowRecord; photos: PhotoItem[] }>,
  photoSources: Map<string, string>,
  documents: Array<{ id: string; name: string; path: string; size: number }>,
): GeneratedAssessmentReport {
  const records = items.map((item) => item.record);
  const total = records.length;
  const normalize = (value: unknown) => String(value ?? '')
    .toLocaleLowerCase('de-DE')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/ß/g, 'ss')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
  const valueOf = (record: WindowRecord, key: string): string => String(record.form_data?.[key] ?? '').trim();
  const inaccessibleRecord = (record: WindowRecord): boolean => {
    const values = [record.accessibility_status, valueOf(record, 'accessibility_status'), valueOf(record, 'accessibility')].map(normalize);
    return values.some((value) => value.includes('nicht zuganglich') || value.includes('nicht zugaenglich') || value.includes('gesperrt'));
  };
  const urgentRecord = (record: WindowRecord): boolean => {
    const values = [record.urgent_action_required, record.danger_immediate, valueOf(record, 'urgent_action_required'), valueOf(record, 'danger_immediate')];
    return values.some((value) => value === true || ['1', 'ja', 'true'].includes(normalize(value)));
  };
  const repairRecord = (record: WindowRecord): boolean => {
    const explicit = normalize([record.overall_rating, valueOf(record, 'overall_rating'), valueOf(record, 'recommended_action'), valueOf(record, 'recommended_measure')].filter(Boolean).join(' '));
    if (/instandsetz|reparatur|ersetzen|austausch/.test(explicit)) return true;
    const note = normalize([valueOf(record, 'visible_special_features'), valueOf(record, 'excel_column_k'), valueOf(record, 'expert_note')].filter(Boolean).join(' '));
    return /beschlag defekt|dichtung defekt|griff fehlt|bolzen fehlt|kipplager fehlt|band fehlt|band ist gebrochen|band ist geborchen|blendrahmen.*gebroch|schliessstuck fehlt|schliessstueck fehlt/.test(note);
  };

  const inaccessible = records.filter(inaccessibleRecord).length;
  const urgent = records.filter(urgentRecord).length;
  const repair = records.filter(repairRecord).length;
  const building = [...new Set(records.map((record) => record.building_label).filter(Boolean))].join(', ') || 'Prüfobjekt';
  const isBonn800 = /(^|\D)800(\D|$)/.test(building) || /fensterpruefung-bonn/i.test(getProjectSlug());

  const floorSortKey = (record: WindowRecord): number => {
    const value = normalize(record.floor_label);
    if (/\beg\b|erdgeschoss/.test(value)) return 0;
    const match = value.match(/\d+/);
    return match ? Number(match[0]) + 1 : Number.MAX_SAFE_INTEGER;
  };
  const roomSortKey = (record: WindowRecord): number => {
    const match = String(record.room_number || record.room_label || '').match(/\d+/);
    return match ? Number(match[0]) : Number.MAX_SAFE_INTEGER;
  };
  const windowSortKey = (record: WindowRecord): number => {
    const match = String(record.window_number || record.record_id || '').match(/\d+/);
    return match ? Number(match[0]) : Number.MAX_SAFE_INTEGER;
  };
  const sortedItems = [...items].sort((left, right) =>
    floorSortKey(left.record) - floorSortKey(right.record)
    || roomSortKey(left.record) - roomSortKey(right.record)
    || windowSortKey(left.record) - windowSortKey(right.record)
  );

  const assessmentText = (record: WindowRecord): string => {
    const inaccessible = inaccessibleRecord(record);
    const repair = repairRecord(record);
    const urgent = urgentRecord(record);
    const parts: string[] = [];
    if (inaccessible) {
      parts.push('Das Fenster war zum Prüfzeitpunkt nicht zugänglich bzw. gesperrt. Eine abschließende Funktions- und Zustandsprüfung war daher nicht möglich. Nach Herstellung der Zugänglichkeit ist eine Nachprüfung erforderlich.');
    } else {
      parts.push('Für dieses Fenster sind Wartung und fachgerechte Einstellung erforderlich.');
    }
    if (repair) parts.push('Darüber hinaus ist aufgrund der dokumentierten Feststellung eine weitergehende Instandsetzung erforderlich.');
    if (urgent) parts.push('Bis zur fachgerechten Instandsetzung ist das Fenster gegen eine gefahrbringende Benutzung zu sichern.');
    if (inaccessible) parts.push('Die grundsätzliche Wartung und fachgerechte Einstellung ist im Zuge der Gesamtmaßnahme dennoch vorzusehen.');
    return parts.join(' ');
  };

  const windowsHtml = sortedItems.map(({ record }, index) => {
    const data = record.form_data ?? {};
    const visibleSpecial = valueOf(record, 'visible_special_features') || valueOf(record, 'excel_column_k') || valueOf(record, 'expert_note') || '';
    const rows: Array<[string, unknown]> = [
      ['Laufende Prüfnummer', record.record_id || '—'],
      ['Fensternummer', record.window_number || 'n/a'],
      ['Etage', record.floor_label || '—'],
      ['Raum', record.room_number || record.room_label || '—'],
      ['Fensterposition im Raum', data.window_position_in_room ?? data.window_position ?? '—'],
      ['Anzahl der Fensterflügel', data.number_of_sashes ?? data.number_of_window_sashes ?? '—'],
      ['Öffnungsart', data.opening_type ?? '—'],
      ['Zugänglichkeit', inaccessibleRecord(record) ? 'Nicht zugänglich' : 'zugänglich'],
      ['Sichtbare Besonderheiten', visibleSpecial || 'Wartung / Einstellung erforderlich'],
      ['Bewertungsstufe', `Wartung und Einstellung erforderlich${repairRecord(record) ? '; weitergehende Instandsetzung erforderlich' : ''}`],
      ['Sofortige Sicherungsmaßnahme erforderlich', urgentRecord(record) ? 'Ja' : 'Nein / nicht vermerkt'],
      ['Bearbeitungsstatus', record.status || '—'],
    ];
    const table = `<table>${rows.map(([label, value]) => `<tr><th>${escapeHtml(String(label))}</th><td>${escapeHtml(String(value ?? '—'))}</td></tr>`).join('')}</table>`;
    return `<section class="window-report"><h2>${index + 1}. Fenster ${escapeHtml(record.window_number || 'n/a')} – Raum ${escapeHtml(record.room_number || record.room_label || '—')}</h2><p><strong>Standort:</strong> ${escapeHtml([record.building_label, record.section_label, record.floor_label, record.room_number || record.room_label].filter(Boolean).join(' · '))}</p><h3>A. Identifikation und dokumentierter Prüfstatus</h3>${table}<h3>B. Sachverständige Einordnung</h3><p>${escapeHtml(assessmentText(record))}</p></section>`;
  }).join('');

  const photoAppendix = sortedItems.map(({ record, photos }, index) => {
    const sources = photos.map((photo) => photoSources.get(photo.id)).filter((source): source is string => Boolean(source));
    if (!sources.length) return '';
    return `<section class="photo-window-group"><h3>${index + 1}. Fenster ${escapeHtml(record.window_number || 'n/a')} – Raum ${escapeHtml(record.room_number || record.room_label || '—')}</h3><div class="photo-appendix">${sources.map((source) => `<figure><img src="${source}" alt=""></figure>`).join('')}</div></section>`;
  }).filter(Boolean).join('') || '<p>Keine Fotos vorhanden.</p>';

  const attachments = documents.length
    ? `<ol>${documents.map((doc) => `<li><a href="https://www.sv-netzwerk.eu/intern/api/sharepoint.php?action=sharepoint_document&amp;id=${encodeURIComponent(doc.id)}">${escapeHtml(doc.path || doc.name)}</a> (${Math.max(1, Math.round(doc.size / 1024))} KB)</li>`).join('')}</ol>`
    : '<p>Im SharePoint-Projektordner wurden keine PDF-Anlagen gefunden.</p>';

  const orderSection = isBonn800
    ? `<section><h2>1. Auftrag und Aufgabenstellung</h2><p>Mit Auftrag vom 16.07.2026, Auftrags-Nr. 4541862491, wurde das Sachverständigenbüro Marc Schütt e.K. durch die Bundesrepublik Deutschland, vertreten durch das Bundesministerium der Verteidigung, mit der Überprüfung der Fensterbeschläge im Gebäude 800 der Liegenschaft Hardthöhe in Bonn beauftragt.</p><p>Gegenstand des Auftrags war die Überprüfung von 450 Fenstern hinsichtlich der Tragfähigkeit und Gebrauchstauglichkeit der vorhandenen Beschlagkomponenten. Der Schwerpunkt lag auf der Prüfung der Flügelecklager sowie der Flügel- bzw. Beschlagscheren hinsichtlich ihrer Eignung für das jeweilige Flügelgewicht sowie auf der Erstellung eines Protokolls mit Prüfergebnis und Hinweisen auf gegebenenfalls erforderliche Maßnahmen.</p><p>Ergänzend umfasst der Auftrag Recherche, technische Auswertung, Dokumentation und Berichterstellung.</p></section>`
    : `<section><h2>1. Auftrag und Aufgabenstellung</h2><p>Gegenstand der Untersuchung war die technische Überprüfung des erfassten Fensterbestands einschließlich Beschlagzustand, Funktionsfähigkeit, Wartungsbedarf und gegebenenfalls erforderlicher weitergehender Maßnahmen.</p></section>`;

  const summarySection = `<section><h2>2. Durchführung und zusammenfassende Feststellungen</h2><p>Im Prüfbestand wurden ${total} Fensterdatensätze dokumentiert. Davon waren ${total - inaccessible} Fenster zugänglich; ${inaccessible} Fenster waren nicht zugänglich bzw. gesperrt und sind nach Herstellung der Zugänglichkeit nachzuprüfen.</p><p>Für sämtliche ${total} erfassten Fenster ist eine fachgerechte Wartung und Einstellung erforderlich. Dieser Grundbedarf gilt unabhängig davon, ob zusätzlich ein konkreter Bauteil- oder Beschlagschaden dokumentiert wurde. Bei ${repair} Fenstern besteht nach dem gespeicherten Prüfstand darüber hinaus ein weitergehender Instandsetzungsbedarf. Bei ${urgent} Fenster${urgent === 1 ? '' : 'n'} ist eine sofortige Sicherungsmaßnahme vermerkt.</p><p>Die Wartung umfasst insbesondere die Kontrolle und gegebenenfalls Wiederherstellung der Beschlagbefestigungen, die fachgerechte Einstellung der Flügel, die Prüfung des vollständigen Schließ- und Kippvorgangs sowie eine abschließende Funktionskontrolle. Fehlende, beschädigte oder nicht mehr sicher funktionsfähige Beschlagteile sind im Rahmen der Instandsetzung fachgerecht zu ersetzen.</p>${isBonn800 ? `<p>Die Differenz zwischen den beauftragten 450 Fenstern und den ${total} tatsächlich dokumentierten Fensterdatensätzen ergibt sich aus dem bei der Untersuchung vorgefundenen bzw. erfassbaren Bestand. Eine belastbare Einzelbewertung nicht im Prüfbestand enthaltener Fenster erfolgt nicht.</p>` : ''}</section>`;

  const technicalSection = `<section><h2>3. Sachverständige Gesamtbewertung</h2><p>Der untersuchte Fensterbestand weist einen flächendeckenden Wartungs- und Einstellbedarf auf. Eine Einstufung einzelner erfasster Fenster als wartungsfrei oder ohne erforderliche Maßnahme ist deshalb nicht sachgerecht.</p><p>Die besondere Prüfaufgabe hinsichtlich der Eignung von Flügelecklagern und Flügel- bzw. Beschlagscheren ist unter Berücksichtigung des jeweiligen Flügelgewichts, des vorhandenen Beschlagsystems, der Lager- und Befestigungssituation sowie des tatsächlichen Erhaltungszustands zu bewerten. Soweit keine belastbare fensterindividuelle Hersteller- oder Bestandszuordnung vorliegt, werden keine lediglich schematisch erzeugten Traglast- oder Gewichtswerte als gesicherte Einzelbefunde ausgegeben.</p><p>Prioritär sind zunächst sicherheitsrelevante Fenster zu sichern, anschließend dokumentierte Bauteil- und Beschlagschäden instand zu setzen. Danach ist der gesamte erfasste Fensterbestand systematisch zu warten und fachgerecht einzustellen. Nicht zugängliche Fenster sind nach Herstellung der Zugänglichkeit nachzuprüfen.</p></section>`;

  const bodyHtml = `<header><h1>Gutachten / Fensterprüfung</h1><p><strong>Objekt:</strong> ${escapeHtml(building)}<br><strong>Datenstand:</strong> ${escapeHtml(new Date().toLocaleString('de-DE'))}</p></header>${orderSection}${summarySection}${technicalSection}<section><h2>4. Einzeldokumentation der Fenster</h2>${windowsHtml}</section><section><h2>5. Anlagen aus dem Projektordner</h2>${attachments}</section><section class="photo-appendix-section"><h2>6. Fotoanhang</h2><p>Die Fotodokumentation ist nach Fenstern und Räumen gruppiert.</p>${photoAppendix}</section>`;
  const safeBuilding = building.replace(/[^a-z0-9äöüß_-]+/gi, '-').replace(/-+/g, '-');
  return { bodyHtml, documentHtml: wrapWordDocument(bodyHtml), fileName: `Gutachten-Fensterpruefung-${safeBuilding}-${new Date().toISOString().slice(0, 10)}.doc` };
}'''

updated = source[:start] + replacement + source[end:]

old_urgent = """  const isUrgent = (record: WindowSummary): boolean =>\n    Boolean(record.urgent_action_required || record.danger_immediate)\n    || /\\bBE\\b|\\bMEG\\b|beschlag\\s+defekt|muss\\s+eingestellt\\s+werden/i.test(remarksFor(record));"""
new_urgent = """  const isUrgent = (record: WindowSummary): boolean =>\n    Boolean(record.urgent_action_required || record.danger_immediate)\n    || /sofort|sperre|absturzgefahr|fenster\\s+(?:faellt|fällt)\\s+raus/i.test(remarksFor(record));"""
if old_urgent in updated:
    updated = updated.replace(old_urgent, new_urgent, 1)

old_maintenance = """  const needsMaintenance = (record: WindowSummary): boolean =>\n    record.form_data?.maintenance_or_repair_due === true\n    || /\\bWA\\b|\\bMEG\\b|\\bSC\\b|\\bSB\\b|wartung\\s+notwendig|muss\\s+eingestellt\\s+werden|schleift|scheibe/i.test(remarksFor(record));"""
new_maintenance = """  const needsMaintenance = (_record: WindowSummary): boolean => true;"""
if old_maintenance in updated:
    updated = updated.replace(old_maintenance, new_maintenance, 1)

if updated != source:
    path.write_text(updated, encoding='utf-8')
    print('Fenster-Gutachtenregeln angewendet.')
else:
    print('Fenster-Gutachtenregeln bereits aktiv.')
