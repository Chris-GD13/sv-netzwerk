import type { ExportDefinition, FieldSection, SelectOption, WindowSummary } from './types';

export const portalProject = {
  code: 'fensterpruefung-bonn',
  title: 'Fensterbeschlagsprüfung BMVg Bonn',
  objectName: '1. Dienstsitz des Bundesministeriums der Verteidigung',
  address: 'Fontainengraben 150, 53123 Bonn',
  plannedWindows: 450,
};

export const roleLabels: Record<string, string> = {
  administrator: 'Administrator',
  pruefer: 'Pruefer',
  auswertung: 'Auswertung',
};

export const statusOptions: SelectOption[] = [
  { value: 'vorbereitet', label: 'Vorbereitet' },
  { value: 'nicht begonnen', label: 'Nicht begonnen' },
  { value: 'in Bearbeitung', label: 'In Bearbeitung' },
  { value: 'Pruefung unterbrochen', label: 'Pruefung unterbrochen' },
  { value: 'nicht zugaenglich', label: 'Nicht zugaenglich' },
  { value: 'technische Rueckfrage offen', label: 'Technische Rueckfrage offen' },
  { value: 'Herstellerrecherche erforderlich', label: 'Herstellerrecherche erforderlich' },
  { value: 'Spezialpruefung erforderlich', label: 'Spezialpruefung erforderlich' },
  { value: 'Pruefung abgeschlossen', label: 'Pruefung abgeschlossen' },
  { value: 'fachlich geprueft', label: 'Fachlich geprueft' },
  { value: 'freigegeben', label: 'Freigegeben' },
];

export const accessibilityOptions: SelectOption[] = [
  { value: 'vollstaendig zugaenglich', label: 'Vollstaendig zugaenglich' },
  { value: 'eingeschraenkt zugaenglich', label: 'Eingeschraenkt zugaenglich' },
  { value: 'nicht zugaenglich', label: 'Nicht zugaenglich' },
];

export const ratingOptions: SelectOption[] = [
  { value: 'ohne festgestellten Handlungsbedarf', label: 'Ohne festgestellten Handlungsbedarf' },
  { value: 'geringfuegige Auffaelligkeit', label: 'Geringfuegige Auffaelligkeit' },
  { value: 'Wartung oder Nachstellung erforderlich', label: 'Wartung oder Nachstellung erforderlich' },
  { value: 'Instandsetzung erforderlich', label: 'Instandsetzung erforderlich' },
  { value: 'Beschlagkomponente austauschen', label: 'Beschlagkomponente austauschen' },
  { value: 'weiterfuehrende Pruefung erforderlich', label: 'Weiterfuehrende Pruefung erforderlich' },
  { value: 'Nutzung vorsorglich einschraenken', label: 'Nutzung vorsorglich einschraenken' },
  { value: 'Nutzung bis zur Klaerung nicht empfohlen', label: 'Nutzung bis zur Klaerung nicht empfohlen' },
  { value: 'nicht abschliessend pruefbar', label: 'Nicht abschliessend pruefbar' },
];

export const priorityOptions: SelectOption[] = [
  { value: 'keine', label: 'Keine' },
  { value: 'niedrig', label: 'Niedrig' },
  { value: 'mittel', label: 'Mittel' },
  { value: 'hoch', label: 'Hoch' },
  { value: 'sofort', label: 'Sofort' },
];

const frameMaterialOptions: SelectOption[] = [
  { value: 'Holz', label: 'Holz' },
  { value: 'Kunststoff', label: 'Kunststoff' },
  { value: 'Aluminium', label: 'Aluminium' },
  { value: 'Holz-Aluminium', label: 'Holz-Aluminium' },
  { value: 'sonstiges', label: 'Sonstiges' },
];

const openingOptions: SelectOption[] = [
  { value: 'Dreh', label: 'Dreh' },
  { value: 'Dreh-Kipp', label: 'Dreh-Kipp' },
  { value: 'Kipp', label: 'Kipp' },
  { value: 'Festverglasung', label: 'Festverglasung' },
  { value: 'sonstige', label: 'Sonstige' },
];

const suitabilityOptions: SelectOption[] = [
  { value: 'geeignet', label: 'Geeignet' },
  { value: 'nicht geeignet', label: 'Nicht geeignet' },
  { value: 'nicht feststellbar', label: 'Nicht abschliessend feststellbar' },
];

const componentStateOptions: SelectOption[] = [
  { value: 'ohne Auffaelligkeit', label: 'Ohne Auffaelligkeit' },
  { value: 'eingeschraenkt funktionsfaehig', label: 'Eingeschraenkt funktionsfaehig' },
  { value: 'beschaedigt', label: 'Beschaedigt' },
  { value: 'fehlt', label: 'Fehlt' },
  { value: 'nicht pruefbar', label: 'Nicht pruefbar' },
  { value: 'nicht vorhanden', label: 'Nicht vorhanden' },
];

export const photoCategoryOptions: SelectOption[] = [
  { value: 'Gesamtansicht', label: 'Gesamtansicht' },
  { value: 'Fensterkennzeichnung', label: 'Fensterkennzeichnung' },
  { value: 'Verglasungskennzeichnung', label: 'Verglasungskennzeichnung' },
  { value: 'Fluegellager', label: 'Fluegellager' },
  { value: 'Ecklager', label: 'Ecklager' },
  { value: 'Beschlagschere', label: 'Beschlagschere' },
  { value: 'Beschlagkennzeichnung', label: 'Beschlagkennzeichnung' },
  { value: 'Mangel', label: 'Mangel' },
  { value: 'sonstiges', label: 'Sonstiges' },
];

const componentFields = [
  'schliesszapfen',
  'schliessbleche',
  'getriebe',
  'griff',
  'eckumlenkung',
  'sicherungseinrichtungen',
  'fehlbedienungssperre',
  'zusaetzliche_fluegelsicherung',
  'sonstige_bauteile',
] as const;

export const windowFormSections: FieldSection[] = [
  {
    id: 'identifikation',
    title: 'A. Identifikation',
    fields: [
      { id: 'inspection_number', label: 'Laufende Pruefnummer', type: 'number', required: true },
      { id: 'window_number', label: 'Fensternummer', type: 'text', required: true },
      { id: 'object_label', label: 'Vorhandene Objektkennzeichnung', type: 'text' },
      { id: 'building_label', label: 'Gebaeude', type: 'text', required: true },
      { id: 'section_label', label: 'Gebaeudeteil', type: 'text', required: true },
      { id: 'floor_label', label: 'Etage', type: 'text', required: true },
      { id: 'room_label', label: 'Raumbezeichnung', type: 'text' },
      { id: 'room_number', label: 'Raumnummer', type: 'text', required: true },
      { id: 'position_in_room', label: 'Fensterposition im Raum', type: 'text' },
      { id: 'orientation', label: 'Himmelsrichtung', type: 'text' },
      { id: 'wing_count', label: 'Anzahl der Fensterfluegel', type: 'number', min: 1, required: true },
      { id: 'inspected_wing', label: 'Gepruefter Fluegel', type: 'text', required: true },
      { id: 'inspector_name', label: 'Pruefer', type: 'text', required: true },
      { id: 'inspection_date', label: 'Pruefdatum', type: 'date', required: true },
      { id: 'time_started', label: 'Uhrzeit Beginn', type: 'time' },
      { id: 'time_finished', label: 'Uhrzeit Abschluss', type: 'time' },
    ],
  },
  {
    id: 'zugaenglichkeit',
    title: 'B. Zugaenglichkeit',
    fields: [
      { id: 'accessibility_status', label: 'Zugaenglichkeit', type: 'select', required: true, options: accessibilityOptions },
      { id: 'access_blocked_furniture', label: 'Zugang durch Moebel behindert', type: 'checkbox' },
      { id: 'access_blocked_fixtures', label: 'Zugang durch Einbauten behindert', type: 'checkbox' },
      { id: 'ladder_unsafe', label: 'Leiter nicht sicher aufstellbar', type: 'checkbox' },
      { id: 'window_not_openable', label: 'Fenster nicht zu oeffnen', type: 'checkbox' },
      { id: 'other_access_issue', label: 'Sonstige Einschraenkung', type: 'text' },
      { id: 'access_note', label: 'Bemerkung zur Zugaenglichkeit', type: 'textarea' },
    ],
  },
  {
    id: 'konstruktion',
    title: 'C. Fenster- und Fluegelkonstruktion',
    fields: [
      { id: 'manufacturer', label: 'Hersteller', type: 'text' },
      { id: 'window_system', label: 'Fenstersystem oder Profilserie', type: 'text' },
      { id: 'construction_year', label: 'Baujahr', type: 'number', min: 1900, max: 2100 },
      { id: 'frame_material', label: 'Rahmenmaterial', type: 'select', options: frameMaterialOptions },
      { id: 'opening_type', label: 'Oeffnungsart', type: 'select', options: openingOptions },
      { id: 'wing_width_mm', label: 'Fluegelbreite mm', type: 'number' },
      { id: 'wing_height_mm', label: 'Fluegelhoehe mm', type: 'number' },
      { id: 'frame_share', label: 'Rahmen- oder Profilanteil', type: 'text' },
      { id: 'visible_special_features', label: 'Sichtbare Besonderheiten', type: 'textarea' },
      { id: 'labels_and_markings', label: 'Typenschilder oder Kennzeichnungen', type: 'textarea' },
    ],
  },
  {
    id: 'verglasung',
    title: 'D. Verglasung und Gewichtsermittlung',
    fields: [
      { id: 'glazing_type', label: 'Verglasungsart', type: 'text' },
      { id: 'glass_panes', label: 'Anzahl Glasscheiben', type: 'number', min: 1 },
      { id: 'glass_structure', label: 'Glasaufbau', type: 'text', required: true, placeholder: 'z. B. 4/16/4' },
      { id: 'glass_thickness_mm', label: 'Glasstaerke gesamt mm', type: 'number', step: '0.1' },
      { id: 'glass_cavity_mm', label: 'Scheibenzwischenraeume mm', type: 'text' },
      { id: 'safety_glass', label: 'Sicherheitsglas', type: 'checkbox' },
      { id: 'replacement_glazing_visible', label: 'Fruehere Austauschverglasung erkennbar', type: 'checkbox' },
      { id: 'glazing_label', label: 'Kennzeichnung der Verglasung', type: 'text' },
      { id: 'glazing_width_mm', label: 'Breite der Verglasung mm', type: 'number', required: true },
      { id: 'glazing_height_mm', label: 'Hoehe der Verglasung mm', type: 'number', required: true },
      { id: 'glass_weight_kg', label: 'Rechnerisches Glasgewicht kg', type: 'number', step: '0.1' },
      { id: 'estimated_frame_weight_kg', label: 'Geschaetztes Rahmengewicht kg', type: 'number', step: '0.1' },
      { id: 'total_wing_weight_kg', label: 'Rechnerisches Gesamtfluegelgewicht kg', type: 'number', step: '0.1' },
      { id: 'weight_from_inventory_kg', label: 'Gewicht aus Bestandsunterlagen kg', type: 'number', step: '0.1' },
      { id: 'weight_from_manufacturer_kg', label: 'Gewicht aus Herstellerunterlagen kg', type: 'number', step: '0.1' },
      { id: 'applied_test_weight_kg', label: 'Angesetztes Pruefgewicht kg', type: 'number', step: '0.1', required: true },
      { id: 'weight_method', label: 'Methode der Gewichtsermittlung', type: 'text', required: true },
      { id: 'safety_margin_kg', label: 'Unsicherheits- oder Sicherheitszuschlag kg', type: 'number', step: '0.1' },
      { id: 'weight_notes', label: 'Bemerkungen zur Gewichtsermittlung', type: 'textarea' },
      { id: 'manual_weight_override', label: 'Manuelle Berechnungsueberschreibung', type: 'checkbox' },
      { id: 'manual_override_reason', label: 'Begruendung fuer manuelle Ueberschreibung', type: 'textarea' },
    ],
  },
  {
    id: 'fluegellager',
    title: 'E. Fluegellager und Ecklager',
    fields: [
      { id: 'hinge_manufacturer', label: 'Hersteller', type: 'text' },
      { id: 'hinge_system', label: 'Beschlagsystem', type: 'text' },
      { id: 'hinge_type_marking', label: 'Typ oder Kennzeichnung', type: 'text' },
      { id: 'hinge_max_weight_kg', label: 'Zulaessiges Fluegelgewicht laut Hersteller kg', type: 'number', step: '0.1' },
      { id: 'hinge_manual_available', label: 'Herstellerunterlage vorhanden', type: 'checkbox' },
      { id: 'hinge_suitability', label: 'Eignung fuer angesetztes Fluegelgewicht', type: 'select', options: suitabilityOptions },
      { id: 'hinge_reserve_kg', label: 'Sicherheitsreserve kg', type: 'number', step: '0.1' },
      { id: 'hinge_fastening_complete', label: 'Befestigung vollstaendig', type: 'checkbox' },
      { id: 'hinge_fastening_loose', label: 'Befestigung gelockert', type: 'checkbox' },
      { id: 'hinge_screws_missing', label: 'Schrauben fehlen', type: 'checkbox' },
      { id: 'hinge_deformation', label: 'Sichtbare Verformung', type: 'checkbox' },
      { id: 'hinge_corrosion', label: 'Korrosion', type: 'checkbox' },
      { id: 'hinge_damage', label: 'Materialschaden', type: 'checkbox' },
      { id: 'hinge_wear', label: 'Verschleiss', type: 'checkbox' },
      { id: 'hinge_play', label: 'Ungewoehnliches Spiel', type: 'checkbox' },
      { id: 'hinge_other', label: 'Sonstige Auffaelligkeit', type: 'text' },
      { id: 'hinge_note', label: 'Bemerkung Fluegellager', type: 'textarea' },
    ],
  },
  {
    id: 'beschlagschere',
    title: 'F. Beschlagschere / Fluegelschere',
    fields: [
      { id: 'scissor_manufacturer', label: 'Hersteller', type: 'text' },
      { id: 'scissor_system', label: 'Beschlagsystem', type: 'text' },
      { id: 'scissor_type_marking', label: 'Typ oder Kennzeichnung', type: 'text' },
      { id: 'scissor_max_weight_kg', label: 'Zulaessiges Fluegelgewicht laut Hersteller kg', type: 'number', step: '0.1' },
      { id: 'scissor_suitability', label: 'Eignung fuer angesetztes Fluegelgewicht', type: 'select', options: suitabilityOptions },
      { id: 'scissor_fastening_complete', label: 'Befestigung vollstaendig', type: 'checkbox' },
      { id: 'scissor_fastening_loose', label: 'Befestigung gelockert', type: 'checkbox' },
      { id: 'scissor_deformation', label: 'Sichtbare Verformung', type: 'checkbox' },
      { id: 'scissor_corrosion', label: 'Korrosion', type: 'checkbox' },
      { id: 'scissor_damage', label: 'Materialschaden', type: 'checkbox' },
      { id: 'scissor_wear', label: 'Verschleiss', type: 'checkbox' },
      { id: 'scissor_play', label: 'Ungewoehnliches Spiel', type: 'checkbox' },
      { id: 'scissor_fatigue', label: 'Hinweise auf Materialermuedung', type: 'checkbox' },
      { id: 'scissor_crack_suspicion', label: 'Verdacht auf Rissbildung', type: 'checkbox' },
      { id: 'scissor_special_inspection', label: 'Spezialpruefung erforderlich', type: 'checkbox' },
      { id: 'scissor_note', label: 'Bemerkung Beschlagschere', type: 'textarea' },
    ],
  },
  {
    id: 'weitere_beschlagkomponenten',
    title: 'G. Weitere Beschlagkomponenten',
    fields: [
      ...componentFields.flatMap((component) => [
        { id: `${component}_state`, label: `${component.replaceAll('_', ' ')} Bewertung`, type: 'select' as const, options: componentStateOptions },
      ]),
      { id: 'hardware_components_note', label: 'Freitextfeld weitere Beschlagkomponenten', type: 'textarea' },
    ],
  },
  {
    id: 'funktionspruefung',
    title: 'H. Funktionspruefung',
    fields: [
      { id: 'opening_possible', label: 'Oeffnen moeglich', type: 'checkbox' },
      { id: 'closing_possible', label: 'Schliessen moeglich', type: 'checkbox' },
      { id: 'tilt_possible', label: 'Kippfunktion moeglich', type: 'checkbox' },
      { id: 'operating_force_normal', label: 'Bedienkraefte unauffaellig', type: 'checkbox' },
      { id: 'wing_scrapes', label: 'Fluegel schleift', type: 'checkbox' },
      { id: 'wing_hangs', label: 'Fluegel haengt', type: 'checkbox' },
      { id: 'unusual_noises', label: 'Ungewoehnliche Geraeusche', type: 'checkbox' },
      { id: 'hardware_heavy', label: 'Beschlag laeuft schwergängig', type: 'checkbox' },
      { id: 'locking_complete', label: 'Verriegelung vollstaendig', type: 'checkbox' },
      { id: 'seal_plausible', label: 'Dichtungsschluss plausibel', type: 'checkbox' },
      { id: 'readjustment_required', label: 'Nachstellung erforderlich', type: 'checkbox' },
      { id: 'safe_use_possible', label: 'Nutzung derzeit sicher moeglich', type: 'checkbox' },
      { id: 'restricted_use_only', label: 'Nutzung nur eingeschraenkt moeglich', type: 'checkbox' },
      { id: 'unsafe_until_repair', label: 'Nutzung bis zur Instandsetzung nicht empfohlen', type: 'checkbox' },
    ],
  },
  {
    id: 'gesamtbewertung',
    title: 'J. Gesamtbewertung',
    fields: [
      { id: 'overall_rating', label: 'Bewertungsstufe', type: 'select', required: true, options: ratingOptions },
      { id: 'hardware_suitability_final', label: 'Beschlag geeignet', type: 'select', options: suitabilityOptions },
      { id: 'danger_immediate', label: 'Gefahr im Verzug', type: 'checkbox' },
      { id: 'urgent_action_required', label: 'Sofortige Sicherungsmassnahme erforderlich', type: 'checkbox' },
      { id: 'special_inspection_required', label: 'Spezialpruefung erforderlich', type: 'checkbox' },
      { id: 'recommended_action', label: 'Empfohlene Massnahme', type: 'textarea', required: true },
      { id: 'priority', label: 'Prioritaet', type: 'select', required: true, options: priorityOptions },
      { id: 'deadline_recommendation', label: 'Fristempfehlung', type: 'text' },
      { id: 'expert_note', label: 'Abschliessende sachverstaendige Bemerkung', type: 'textarea' },
    ],
  },
  {
    id: 'bearbeitungsstatus',
    title: 'K. Bearbeitungsstatus',
    fields: [
      { id: 'status', label: 'Status', type: 'select', required: true, options: statusOptions },
      { id: 'summary_before_completion', label: 'Zusammenfassung vor Abschluss', type: 'textarea' },
      { id: 'completion_confirmed', label: 'Abschluss bestaetigt', type: 'checkbox' },
      { id: 'release_reason', label: 'Begruendung fuer Aenderung nach Freigabe', type: 'textarea' },
    ],
  },
];

export const listFilters = [
  'building_label',
  'section_label',
  'floor_label',
  'room_number',
  'assigned_name',
  'status',
  'overall_rating',
  'special_inspection_required',
] as const;

export const requiredBeforeCompletion = [
  'inspection_number',
  'window_number',
  'building_label',
  'section_label',
  'floor_label',
  'room_number',
  'wing_count',
  'inspected_wing',
  'inspector_name',
  'inspection_date',
  'accessibility_status',
  'glass_structure',
  'glazing_width_mm',
  'glazing_height_mm',
  'applied_test_weight_kg',
  'weight_method',
  'overall_rating',
  'recommended_action',
  'priority',
  'status',
] as const;

export const exportDefinitions: ExportDefinition[] = [
  {
    id: 'csv-all',
    title: 'CSV-Gesamtexport',
    description: 'Alle Fensterdatensaetze fuer Tabellenkalkulationen.',
    filter: () => true,
  },
  {
    id: 'excel-all',
    title: 'Excel-kompatibler Export',
    description: 'Semikolon-getrennte CSV fuer Excel.',
    filter: () => true,
  },
  {
    id: 'maengel',
    title: 'Maengelliste',
    description: 'Fenster mit Handlungsbedarf, Auffaelligkeiten oder Austauschbedarf.',
    filter: (record) => Boolean(record.has_defect || (record.overall_rating && record.overall_rating !== 'ohne festgestellten Handlungsbedarf')),
  },
  {
    id: 'inaccessible',
    title: 'Liste nicht zugaenglicher Fenster',
    description: 'Alle Datensaetze mit eingeschraenkter oder fehlender Zugaenglichkeit.',
    filter: (record) => record.accessibility_status === 'nicht zugaenglich',
  },
  {
    id: 'special',
    title: 'Liste Spezialpruefungen',
    description: 'Fenster mit markierter Spezialpruefung.',
    filter: (record) => record.special_inspection_required,
  },
  {
    id: 'urgent',
    title: 'Dringende Sicherungsmassnahmen',
    description: 'Fenster mit sofortigem Handlungsbedarf.',
    filter: (record) => record.urgent_action_required || record.danger_immediate,
  },
];

export function getFieldDefinition(fieldId: string) {
  return windowFormSections.flatMap((section) => section.fields).find((field) => field.id === fieldId);
}

export function getFilterValue(record: WindowSummary, key: string): string {
  const value = (record as unknown as Record<string, unknown>)[key];
  if (typeof value === 'boolean') return value ? 'ja' : 'nein';
  return String(value ?? '');
}
