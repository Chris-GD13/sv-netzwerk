export type PortalRole = 'administrator' | 'pruefer' | 'auswertung';

export type PortalRoute = 'landing' | 'login' | 'dashboard' | 'windows' | 'record' | 'analysis' | 'export';

export type WindowStatus =
  | 'vorbereitet'
  | 'nicht begonnen'
  | 'in Bearbeitung'
  | 'Pruefung unterbrochen'
  | 'nicht zugaenglich'
  | 'technische Rueckfrage offen'
  | 'Herstellerrecherche erforderlich'
  | 'Spezialpruefung erforderlich'
  | 'Pruefung abgeschlossen'
  | 'fachlich geprueft'
  | 'freigegeben';

export interface Profile {
  id: string;
  email?: string | null;
  full_name?: string | null;
  role: PortalRole;
  is_active?: boolean | null;
}

export interface PortalUser {
  id: string;
  email: string;
  profile: Profile;
}

export interface WindowSummary {
  id: string;
  record_id: string;
  inspection_number: number | null;
  window_number: string;
  room_number: string | null;
  room_label: string | null;
  building_label: string | null;
  section_label: string | null;
  floor_label: string | null;
  status: WindowStatus;
  overall_rating: string | null;
  priority: string | null;
  accessibility_status: string | null;
  assigned_to: string | null;
  assigned_name: string | null;
  special_inspection_required: boolean;
  urgent_action_required: boolean;
  has_defect: boolean;
  danger_immediate: boolean;
  last_edited_at: string | null;
  updated_at: string;
  progress_percent: number;
  lock_owner_id?: string | null;
  lock_owner_name?: string | null;
  lock_expires_at?: string | null;
}

export interface WindowRecord extends WindowSummary {
  project_id: string;
  form_data: Record<string, unknown>;
  calculated_data: Record<string, unknown>;
  last_saved_locally_at?: string | null;
  completed_at?: string | null;
  released_at?: string | null;
  release_reason?: string | null;
  version: number;
}

export interface DashboardStats {
  total: number;
  notStarted: number;
  inProgress: number;
  completed: number;
  withDefect: number;
  urgent: number;
  specialInspection: number;
  inaccessible: number;
  touchedToday: number;
  byInspector: Array<{ id: string; name: string; total: number; completed: number }>;
  recentChanges: Array<{ id: string; label: string; updatedAt: string; user?: string | null; status: string }>;
}

export interface OfflineDraft {
  windowId: string;
  data: Record<string, unknown>;
  calculatedData: Record<string, unknown>;
  updatedAt: string;
  unsyncedChanges: string[];
}

export interface SelectOption {
  value: string;
  label: string;
}

export interface FieldDefinition {
  id: string;
  label: string;
  type: 'text' | 'textarea' | 'number' | 'date' | 'time' | 'select' | 'checkbox';
  required?: boolean;
  placeholder?: string;
  step?: string;
  min?: number;
  max?: number;
  description?: string;
  options?: SelectOption[];
}

export interface FieldSection {
  id: string;
  title: string;
  description?: string;
  fields: FieldDefinition[];
}

export interface ExportDefinition {
  id: string;
  title: string;
  description: string;
  filter: (record: WindowSummary) => boolean;
}

export interface AuditLogEntry {
  id: string;
  action_type: string;
  field_name: string | null;
  old_value: string | null;
  new_value: string | null;
  reason: string | null;
  created_at: string;
  actor_name?: string | null;
}

export interface PhotoItem {
  id: string;
  window_id: string;
  category: string;
  caption: string | null;
  file_name: string;
  taken_at: string | null;
  inspector_name?: string | null;
  storage_path: string;
}

export interface LockResult {
  ok: boolean;
  lock_id?: string;
  owner_id?: string | null;
  owner_name?: string | null;
  expires_at?: string | null;
  message?: string;
}

export interface CalculationParameterMap {
  glassDensityKgPerM2Mm: number;
  frameWeightFactor: number;
  safetyFactor: number;
}
