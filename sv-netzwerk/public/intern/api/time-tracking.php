<?php
/**
 * Zeiterfassung – Portalbuchungen, Urlaub, Zeitausgleich und TimeMoto-Import.
 * Alle Zeitwerte werden in UTC gespeichert. Überstunden werden als Saldo geführt;
 * eine Auszahlung wird niemals automatisch erzeugt.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$actor = requireAuth();
ensureTimeTrackingSchema();
$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? 'summary');

match (true) {
    $method === 'GET' && $action === 'summary' => handleSummary($actor),
    $method === 'POST' && $action === 'clock_in' => handleClockIn($actor),
    $method === 'POST' && $action === 'clock_out' => handleClockOut($actor),
    $method === 'POST' && $action === 'leave_submit' => handleLeaveSubmit($actor),
    $method === 'POST' && $action === 'leave_decide' => handleLeaveDecision($actor),
    $method === 'POST' && $action === 'save_leave_entitlement' => handleSaveLeaveEntitlement($actor),
    $method === 'POST' && $action === 'save_entry' => handleSaveEntry($actor),
    $method === 'POST' && $action === 'import_timemoto' => handleTimeMotoImport($actor),
    default => apiError(404, 'Unbekannter Zeiterfassungs-Endpunkt.'),
};

function ensureTimeTrackingSchema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS time_user_settings (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        target_minutes_per_day INT UNSIGNED NULL,
        annual_leave_days DECIMAL(5,2) NULL,
        carryover_leave_days DECIMAL(5,2) NOT NULL DEFAULT 0,
        contractual_minutes_per_week INT UNSIGNED NULL,
        hourly_wage DECIMAL(10,2) NULL,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT fk_time_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ttEnsureColumn($pdo, 'annual_leave_days', 'DECIMAL(5,2) NULL');
    ttEnsureColumn($pdo, 'carryover_leave_days', 'DECIMAL(5,2) NOT NULL DEFAULT 0');
    ttEnsureColumn($pdo, 'contractual_minutes_per_week', 'INT UNSIGNED NULL');
    ttEnsureColumn($pdo, 'hourly_wage', 'DECIMAL(10,2) NULL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS time_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        started_at DATETIME NOT NULL,
        ended_at DATETIME NULL,
        break_minutes INT UNSIGNED NOT NULL DEFAULT 0,
        worked_minutes INT UNSIGNED NOT NULL DEFAULT 0,
        planned_minutes INT UNSIGNED NULL,
        balance_minutes INT NOT NULL DEFAULT 0,
        source VARCHAR(32) NOT NULL DEFAULT 'portal',
        external_key CHAR(64) NULL,
        note TEXT NULL,
        created_by INT UNSIGNED NOT NULL,
        updated_by INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_time_entries_external (external_key),
        KEY idx_time_entries_user_started (user_id, started_at),
        CONSTRAINT fk_time_entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS time_leave_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        leave_type VARCHAR(24) NOT NULL,
        starts_on DATE NOT NULL,
        ends_on DATE NOT NULL,
        hours DECIMAL(7,2) NOT NULL DEFAULT 0,
        note TEXT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        source VARCHAR(32) NOT NULL DEFAULT 'portal',
        external_key CHAR(64) NULL,
        requested_by INT UNSIGNED NOT NULL,
        decided_by INT UNSIGNED NULL,
        decided_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_time_leave_external (external_key),
        KEY idx_time_leave_user_dates (user_id, starts_on, ends_on),
        KEY idx_time_leave_status (status),
        CONSTRAINT fk_time_leave_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS time_audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        actor_user_id INT UNSIGNED NOT NULL,
        action VARCHAR(40) NOT NULL,
        entity_type VARCHAR(24) NOT NULL,
        entity_id BIGINT UNSIGNED NULL,
        before_json JSON NULL,
        after_json JSON NULL,
        created_at DATETIME NOT NULL,
        KEY idx_time_audit_created (created_at),
        CONSTRAINT fk_time_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ttEnsureColumn(PDO $pdo, string $column, string $definition): void
{
    $allowed = ['annual_leave_days', 'carryover_leave_days', 'contractual_minutes_per_week', 'hourly_wage'];
    if (!in_array($column, $allowed, true)) return;
    $exists = $pdo->query('SHOW COLUMNS FROM time_user_settings LIKE ' . $pdo->quote($column))->fetch();
    if (!$exists) $pdo->exec("ALTER TABLE time_user_settings ADD COLUMN `$column` $definition");
}

function ttText(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    return strtr($value, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
}

function ttIdentity(array $user): string
{
    $email = ttText((string)($user['email'] ?? ''));
    $name = ttText((string)($user['full_name'] ?? ''));
    if (str_contains($email, 'cw@') || str_contains($name, 'christian waechter')) return 'christian';
    if (str_contains($email, 'marc') || str_contains($name, 'marc schuett')) return 'marc';
    if (str_contains($email, 'katja') || str_contains($name, 'katja')) return 'katja';
    if (str_contains($email, 'ws@') || str_contains($email, 'sanne') || str_contains($email, 'susanne') || str_contains($name, 'sanne') || str_contains($name, 'susanne')) return 'sanne';
    return '';
}

function ttPermissions(array $user): array
{
    $identity = ttIdentity($user);
    $admin = $identity === 'christian' && (string)($user['role'] ?? '') === 'administrator';
    return [
        'identity' => $identity,
        'can_view_all' => $admin || $identity === 'marc',
        'can_edit_all' => $admin,
        'can_approve_leave' => $admin || $identity === 'marc',
    ];
}

function ttEnsureKnownUser(array $actor, int $userId): void
{
    $permissions = ttPermissions($actor);
    if ($permissions['can_edit_all']) return;
    if ((int)$actor['id'] !== $userId) apiError(403, 'Nur Administratoren dürfen Zeitbuchungen anderer Personen verändern.');
}

function ttUsers(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id,email,full_name,role,is_active FROM users WHERE is_active=1 ORDER BY full_name')->fetchAll();
    return array_values(array_filter($rows, static fn(array $row): bool => ttIdentity($row) !== ''));
}

function ttUser(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id,email,full_name,role,is_active FROM users WHERE id=:id AND is_active=1');
    $stmt->execute([':id'=>$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ttTarget(PDO $pdo, int $userId): ?int
{
    $stmt = $pdo->prepare('SELECT target_minutes_per_day FROM time_user_settings WHERE user_id=:id');
    $stmt->execute([':id'=>$userId]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? null : (int)$value;
}

function ttLeaveDays(array $leave, ?int $targetMinutes): float
{
    if ((string)($leave['leave_type'] ?? '') !== 'urlaub') return 0.0;
    $hours = (float)($leave['hours'] ?? 0);
    if ($hours > 0 && $targetMinutes && $targetMinutes > 0) return round(($hours * 60) / $targetMinutes, 2);
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($leave['starts_on'] ?? ''));
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($leave['ends_on'] ?? ''));
    if (!$from || !$to || $to < $from) return 0.0;
    $days = 0;
    for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
        $weekday = (int)$day->format('N');
        if ($weekday <= 5) $days++;
    }
    return (float)$days;
}

function ttParseMinutes(?string $value): ?int
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $negative = str_starts_with($value, '-');
    $value = ltrim($value, '+-');
    if (!preg_match('/^(\d{1,3}):([0-5]\d)$/', $value, $m)) return null;
    $minutes = ((int)$m[1] * 60) + (int)$m[2];
    return $negative ? -$minutes : $minutes;
}

function ttDateTime(string $date, ?string $time = null): ?string
{
    $date = trim($date);
    $time = trim((string)$time);
    if ($date === '') return null;
    $formats = $time !== '' ? ['d-m-Y H:i','d.m.Y H:i','Y-m-d H:i'] : ['d-m-Y','d.m.Y','Y-m-d'];
    foreach ($formats as $format) {
        $dateTime = DateTimeImmutable::createFromFormat('!' . $format, $time !== '' ? $date . ' ' . $time : $date, new DateTimeZone('UTC'));
        if ($dateTime instanceof DateTimeImmutable) return $dateTime->format('Y-m-d H:i:s');
    }
    return null;
}

function ttFormatMinutes(int $minutes): string
{
    $sign = $minutes < 0 ? '-' : '';
    $minutes = abs($minutes);
    return $sign . intdiv($minutes, 60) . ':' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT);
}

function ttAudit(array $actor, string $action, string $entity, ?int $entityId, ?array $before, ?array $after): void
{
    try {
        db()->prepare('INSERT INTO time_audit_log (actor_user_id,action,entity_type,entity_id,before_json,after_json,created_at) VALUES (:actor,:action,:entity,:id,:before,:after,:now)')->execute([
            ':actor'=>(int)$actor['id'], ':action'=>$action, ':entity'=>$entity, ':id'=>$entityId,
            ':before'=>$before ? json_encode($before, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
            ':after'=>$after ? json_encode($after, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
            ':now'=>nowUtc(),
        ]);
    } catch (Throwable $e) { error_log('[time-tracking] Audit fehlgeschlagen: ' . $e->getMessage()); }
}

function handleSummary(array $actor): never
{
    $pdo = db();
    $permissions = ttPermissions($actor);
    $users = ttUsers($pdo);
    $visibleIds = $permissions['can_view_all'] ? array_map(static fn(array $u): int => (int)$u['id'], $users) : [(int)$actor['id']];
    if (!$visibleIds) apiJson(['ok'=>true,'actor'=>['id'=>(int)$actor['id'],'name'=>$actor['full_name'],'identity'=>$permissions['identity']],'permissions'=>$permissions,'users'=>[],'entries'=>[],'leaves'=>[]]);
    $placeholders = implode(',', array_fill(0, count($visibleIds), '?'));
    $stmt = $pdo->prepare("SELECT e.*,u.full_name,u.email FROM time_entries e JOIN users u ON u.id=e.user_id WHERE e.user_id IN ($placeholders) ORDER BY e.started_at DESC LIMIT 500");
    $stmt->execute($visibleIds);
    $entries = $stmt->fetchAll();
    $leaveStmt = $pdo->prepare("SELECT l.*,u.full_name,u.email FROM time_leave_requests l JOIN users u ON u.id=l.user_id WHERE l.user_id IN ($placeholders) ORDER BY l.starts_on DESC,l.id DESC LIMIT 300");
    $leaveStmt->execute($visibleIds);
    $leaves = $leaveStmt->fetchAll();
    $settingsStmt = $pdo->prepare("SELECT * FROM time_user_settings WHERE user_id IN ($placeholders)");
    $settingsStmt->execute($visibleIds);
    $settingsByUser = [];
    foreach ($settingsStmt->fetchAll() as $setting) $settingsByUser[(int)$setting['user_id']] = $setting;
    $yearStart = date('Y-01-01 00:00:00');
    $year = (int)date('Y');
    $aggregate = [];
    foreach ($users as $user) {
        if (!$permissions['can_view_all'] && (int)$user['id'] !== (int)$actor['id']) continue;
        $worked = 0; $balance = 0; $open = false; $hasEntries = false;
        foreach ($entries as $entry) if ((int)$entry['user_id'] === (int)$user['id'] && (string)$entry['started_at'] >= $yearStart) { $hasEntries = true; $worked += (int)$entry['worked_minutes']; $balance += (int)$entry['balance_minutes']; $open = $open || $entry['ended_at'] === null; }
        $compStmt = $pdo->prepare("SELECT COALESCE(SUM(hours),0) FROM time_leave_requests WHERE user_id=:id AND leave_type='zeitausgleich' AND status='approved' AND ends_on >= :year_start");
        $compStmt->execute([':id'=>(int)$user['id'], ':year_start'=>substr($yearStart,0,10)]);
        $compMinutes = (int)round(((float)$compStmt->fetchColumn()) * 60);
        $targetMinutes = ttTarget($pdo,(int)$user['id']);
        $setting = $settingsByUser[(int)$user['id']] ?? [];
        $annualDays = !array_key_exists('annual_leave_days', $setting) || $setting['annual_leave_days'] === null ? null : (float)$setting['annual_leave_days'];
        $carryoverDays = (float)($setting['carryover_leave_days'] ?? 0);
        $takenDays = 0.0; $pendingDays = 0.0;
        foreach ($leaves as $leave) if ((int)$leave['user_id'] === (int)$user['id'] && (int)substr((string)$leave['starts_on'], 0, 4) === $year) {
            $days = ttLeaveDays($leave, $targetMinutes);
            if ((string)$leave['status'] === 'approved') $takenDays += $days;
            if ((string)$leave['status'] === 'pending') $pendingDays += $days;
        }
        $entitlementDays = $annualDays === null ? null : round($annualDays + $carryoverDays, 2);
        $remainingDays = $entitlementDays === null ? null : round($entitlementDays - $takenDays, 2);
        $aggregate[] = [
            'id'=>(int)$user['id'],'identity'=>ttIdentity($user),'name'=>$user['full_name'],
            'worked_minutes'=>$worked,'balance_minutes'=>$balance,'compensated_minutes'=>$compMinutes,'open'=>$open,'has_entries'=>$hasEntries,
            'target_minutes_per_day'=>$targetMinutes,
            'contractual_minutes_per_week'=>array_key_exists('contractual_minutes_per_week', $setting) && $setting['contractual_minutes_per_week'] !== null ? (int)$setting['contractual_minutes_per_week'] : null,
            'hourly_wage'=>array_key_exists('hourly_wage', $setting) && $setting['hourly_wage'] !== null ? (float)$setting['hourly_wage'] : null,
            'vacation'=>['annual_days'=>$annualDays,'carryover_days'=>round($carryoverDays,2),'entitlement_days'=>$entitlementDays,'taken_days'=>round($takenDays,2),'pending_days'=>round($pendingDays,2),'remaining_days'=>$remainingDays],
        ];
    }
    apiJson(['ok'=>true,'year'=>$year,'actor'=>['id'=>(int)$actor['id'],'name'=>$actor['full_name'],'identity'=>$permissions['identity']],'permissions'=>$permissions,'users'=>$aggregate,'entries'=>$entries,'leaves'=>$leaves]);
}

function handleClockIn(array $actor): never
{
    $pdo = db();
    $userId = (int)$actor['id'];
    $existing = $pdo->prepare('SELECT id FROM time_entries WHERE user_id=:id AND ended_at IS NULL LIMIT 1');
    $existing->execute([':id'=>$userId]);
    if ($existing->fetchColumn()) apiError(409, 'Für dieses Konto läuft bereits eine Buchung.');
    $now = nowUtc();
    $pdo->prepare('INSERT INTO time_entries (user_id,started_at,worked_minutes,planned_minutes,balance_minutes,source,created_by,updated_by,created_at,updated_at) VALUES (:user,:started,0,:planned,0,\'portal\',:creator,:updater,:created,:updated)')->execute([
        ':user'=>$userId, ':started'=>$now, ':planned'=>ttTarget($pdo,$userId), ':creator'=>$userId, ':updater'=>$userId, ':created'=>$now, ':updated'=>$now,
    ]);
    $id = (int)$pdo->lastInsertId();
    ttAudit($actor,'clock_in','time_entry',$id,null,['user_id'=>$userId,'started_at'=>$now]);
    apiJson(['ok'=>true,'id'=>$id,'started_at'=>$now]);
}

function handleClockOut(array $actor): never
{
    $pdo = db(); $userId = (int)$actor['id'];
    $stmt = $pdo->prepare('SELECT * FROM time_entries WHERE user_id=:id AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1'); $stmt->execute([':id'=>$userId]); $entry=$stmt->fetch();
    if (!$entry) apiError(409,'Für dieses Konto läuft keine offene Buchung.');
    $now = nowUtc(); $worked = max(0,(int)floor((strtotime($now)-strtotime((string)$entry['started_at']))/60)) - (int)$entry['break_minutes'];
    $planned = $entry['planned_minutes'] === null ? null : (int)$entry['planned_minutes']; $balance = $planned === null ? 0 : $worked - $planned;
    $pdo->prepare('UPDATE time_entries SET ended_at=:ended,worked_minutes=:worked,balance_minutes=:balance,updated_by=:updater,updated_at=:updated WHERE id=:id')->execute([':ended'=>$now,':worked'=>$worked,':balance'=>$balance,':updater'=>$userId,':updated'=>$now,':id'=>(int)$entry['id']]);
    ttAudit($actor,'clock_out','time_entry',(int)$entry['id'],$entry,['ended_at'=>$now,'worked_minutes'=>$worked,'balance_minutes'=>$balance]);
    apiJson(['ok'=>true,'id'=>(int)$entry['id'],'ended_at'=>$now,'worked_minutes'=>$worked,'balance_minutes'=>$balance]);
}

function handleSaveEntry(array $actor): never
{
    $permissions=ttPermissions($actor); if (!$permissions['can_edit_all']) apiError(403,'Nur Christian darf Zeitbuchungen nachtragen oder ändern.');
    $body=requestBody(); $pdo=db(); $id=(int)($body['id']??0); $userId=(int)($body['user_id']??0); $user=ttUser($pdo,$userId); if (!$user) apiError(400,'Mitarbeiter nicht gefunden.');
    $started=ttDateTime((string)($body['date']??''),(string)($body['start']??'')); $ended=ttDateTime((string)($body['date']??''),(string)($body['end']??''));
    if (!$started) apiError(400,'Datum und Beginn sind erforderlich.'); if ($ended && strtotime($ended)<strtotime($started)) apiError(400,'Das Ende muss nach dem Beginn liegen.');
    $break=max(0,(int)($body['break_minutes']??0)); $worked=$ended?max(0,(int)floor((strtotime($ended)-strtotime($started))/60)-$break):0; $planned=array_key_exists('planned_minutes',$body)&&$body['planned_minutes']!==''?(int)$body['planned_minutes']:ttTarget($pdo,$userId); $balance=array_key_exists('balance_minutes',$body)&&$body['balance_minutes']!==''?(int)$body['balance_minutes']:($planned===null?0:$worked-$planned); $now=nowUtc();
    if ($id>0) { $q=$pdo->prepare('SELECT * FROM time_entries WHERE id=:id');$q->execute([':id'=>$id]);$before=$q->fetch();if(!$before)apiError(404,'Zeitbuchung nicht gefunden.');$pdo->prepare('UPDATE time_entries SET user_id=:user,started_at=:started,ended_at=:ended,break_minutes=:break,worked_minutes=:worked,planned_minutes=:planned,balance_minutes=:balance,note=:note,source=\'portal\',updated_by=:updater,updated_at=:updated WHERE id=:id')->execute([':user'=>$userId,':started'=>$started,':ended'=>$ended,':break'=>$break,':worked'=>$worked,':planned'=>$planned,':balance'=>$balance,':note'=>trim((string)($body['note']??'')),':updater'=>(int)$actor['id'],':updated'=>$now,':id'=>$id]);ttAudit($actor,'entry_update','time_entry',$id,$before,['user_id'=>$userId,'started_at'=>$started,'ended_at'=>$ended,'worked_minutes'=>$worked,'balance_minutes'=>$balance]);apiJson(['ok'=>true,'id'=>$id]); }
    $pdo->prepare('INSERT INTO time_entries (user_id,started_at,ended_at,break_minutes,worked_minutes,planned_minutes,balance_minutes,source,note,created_by,updated_by,created_at,updated_at) VALUES (:user,:started,:ended,:break,:worked,:planned,:balance,\'portal\',:note,:creator,:updater,:created,:updated)')->execute([':user'=>$userId,':started'=>$started,':ended'=>$ended,':break'=>$break,':worked'=>$worked,':planned'=>$planned,':balance'=>$balance,':note'=>trim((string)($body['note']??'')),':creator'=>(int)$actor['id'],':updater'=>(int)$actor['id'],':created'=>$now,':updated'=>$now]);$newId=(int)$pdo->lastInsertId();ttAudit($actor,'entry_create','time_entry',$newId,null,['user_id'=>$userId,'started_at'=>$started,'ended_at'=>$ended,'worked_minutes'=>$worked,'balance_minutes'=>$balance]);apiJson(['ok'=>true,'id'=>$newId],201);
}

function handleLeaveSubmit(array $actor): never
{
    $body=requestBody();$pdo=db();$userId=(int)($body['user_id']??$actor['id']);ttEnsureKnownUser($actor,$userId);$type=(string)($body['leave_type']??'urlaub');if(!in_array($type,['urlaub','zeitausgleich','krank','sonderurlaub'],true))apiError(400,'Ungültige Abwesenheitsart.');$from=(string)($body['starts_on']??'');$to=(string)($body['ends_on']??$from);$fromDate=DateTimeImmutable::createFromFormat('!Y-m-d',$from);$toDate=DateTimeImmutable::createFromFormat('!Y-m-d',$to);if(!$fromDate||!$toDate||$toDate<$fromDate)apiError(400,'Gültiger Zeitraum erforderlich.');$hours=max(0,(float)($body['hours']??0));$now=nowUtc();$pdo->prepare('INSERT INTO time_leave_requests (user_id,leave_type,starts_on,ends_on,hours,note,status,source,requested_by,created_at,updated_at) VALUES (:user,:type,:from,:to,:hours,:note,\'pending\',\'portal\',:requester,:created,:updated)')->execute([':user'=>$userId,':type'=>$type,':from'=>$from,':to'=>$to,':hours'=>$hours,':note'=>trim((string)($body['note']??'')),':requester'=>(int)$actor['id'],':created'=>$now,':updated'=>$now]);$id=(int)$pdo->lastInsertId();ttAudit($actor,'leave_submit','leave_request',$id,null,['user_id'=>$userId,'leave_type'=>$type,'starts_on'=>$from,'ends_on'=>$to,'hours'=>$hours]);apiJson(['ok'=>true,'id'=>$id],201);
}

function handleLeaveDecision(array $actor): never
{
    $permissions=ttPermissions($actor);if(!$permissions['can_approve_leave'])apiError(403,'Nur Christian oder Marc dürfen Urlaube genehmigen.');$body=requestBody();$id=(int)($body['id']??0);$status=(string)($body['status']??'');if(!$id||!in_array($status,['approved','rejected'],true))apiError(400,'Entscheidung unvollständig.');$pdo=db();$q=$pdo->prepare('SELECT * FROM time_leave_requests WHERE id=:id');$q->execute([':id'=>$id]);$before=$q->fetch();if(!$before)apiError(404,'Urlaubsantrag nicht gefunden.');$now=nowUtc();$pdo->prepare('UPDATE time_leave_requests SET status=:status,decided_by=:decider,decided_at=:decided,updated_at=:updated WHERE id=:id')->execute([':status'=>$status,':decider'=>(int)$actor['id'],':decided'=>$now,':updated'=>$now,':id'=>$id]);ttAudit($actor,'leave_'.$status,'leave_request',$id,$before,['status'=>$status,'decided_by'=>(int)$actor['id']]);apiJson(['ok'=>true,'id'=>$id,'status'=>$status]);
}

function xlsxSharedStrings(ZipArchive $zip): array
{
    if (!$zip->locateName('xl/sharedStrings.xml')) return [];
    $xml=simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'));$strings=[];foreach($xml->si as $si)$strings[]=(string)($si->t ?? implode('',array_map('strval',(array)$si->r->t)));return $strings;
}
function xlsxCellValue(SimpleXMLElement $cell,array $strings): string
{
    $type=(string)($cell['t']??'');$value=(string)($cell->v);if($type==='s')return $strings[(int)$value]??'';if($type==='inlineStr')return (string)($cell->is->t);return $value;
}
function timeMotoRows(string $path): array
{
    $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('Excel-Datei konnte nicht geöffnet werden.');$strings=xlsxSharedStrings($zip);$workbook=simplexml_load_string($zip->getFromName('xl/workbook.xml'));$rels=simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));$targets=[];foreach($rels->Relationship as $rel)$targets[(string)$rel['Id']]=(string)$rel['Target'];$sheet=$workbook->sheets->sheet[0];$rid=(string)$sheet->attributes('r',true)->id;$target=$targets[$rid]??'worksheets/sheet1.xml';$target=ltrim($target,'/');if(!str_starts_with($target,'xl/'))$target='xl/'.$target;$xml=simplexml_load_string($zip->getFromName($target));$rows=[];$headers=[];foreach($xml->sheetData->row as $row){$values=[];foreach($row->c as $cell){$ref=(string)$cell['r'];preg_match('/([A-Z]+)/',$ref,$m);$col=0;foreach(str_split($m[1]??'A') as $char)$col=$col*26+(ord($char)-64);$values[$col]=xlsxCellValue($cell,$strings);}if(!$headers){foreach($values as $col=>$value)$headers[$col]=trim((string)$value);continue;}if($values){$record=[];foreach(array_keys($headers) as $col){$key=$headers[$col];if($key!=='')$record[$key]=(string)($values[$col]??'');}if($record){$previous=$rows[count($rows)-1]??null;if($previous&&trim((string)($record['Datum']??''))===''&&trim((string)($record['Remarks']??''))!==''){foreach(['Vorname','Nachname','Datum'] as $carry){if(trim((string)($record[$carry]??''))==='')$record[$carry]=(string)($previous[$carry]??'');}}$rows[]=$record;}}}$zip->close();return $rows;
}
function findTimeMotoUser(PDO $pdo,string $first,string $last): ?array
{
    $needle=ttText($first.' '.$last);$users=ttUsers($pdo);foreach($users as $user){$name=ttText((string)$user['full_name']);if($first!==''&&str_contains($name,ttText($first))&&($last===''||str_contains($name,ttText($last))))return $user;}foreach($users as $user)if($first!==''&&str_contains(ttText((string)$user['full_name']),ttText($first)))return $user;return null;
}
function handleSaveLeaveEntitlement(array $actor): never
{
    $permissions = ttPermissions($actor);
    if (!$permissions['can_edit_all']) apiError(403, 'Nur Christian darf Urlaubsansprüche ändern.');
    $body = requestBody();
    $userId = (int)($body['user_id'] ?? 0);
    $pdo = db();
    $user = ttUser($pdo, $userId);
    if (!$user || ttIdentity($user) === '') apiError(400, 'Mitarbeiter nicht gefunden.');
    $annual = array_key_exists('annual_leave_days', $body) && $body['annual_leave_days'] !== '' && $body['annual_leave_days'] !== null ? (float)$body['annual_leave_days'] : null;
    $carryover = (float)($body['carryover_leave_days'] ?? 0);
    $weeklyMinutes = array_key_exists('contractual_minutes_per_week', $body) && $body['contractual_minutes_per_week'] !== '' && $body['contractual_minutes_per_week'] !== null ? (int)$body['contractual_minutes_per_week'] : null;
    $dailyMinutes = array_key_exists('target_minutes_per_day', $body) && $body['target_minutes_per_day'] !== '' && $body['target_minutes_per_day'] !== null ? (int)$body['target_minutes_per_day'] : null;
    $hourlyWage = array_key_exists('hourly_wage', $body) && $body['hourly_wage'] !== '' && $body['hourly_wage'] !== null ? (float)$body['hourly_wage'] : null;
    if (($annual !== null && ($annual < 0 || $annual > 365)) || $carryover < 0 || $carryover > 365 || ($weeklyMinutes !== null && ($weeklyMinutes < 0 || $weeklyMinutes > 10080)) || ($dailyMinutes !== null && ($dailyMinutes < 0 || $dailyMinutes > 1440)) || ($hourlyWage !== null && ($hourlyWage < 0 || $hourlyWage > 10000))) apiError(400, 'Anspruch, Arbeitszeit und Stundenlohn enthalten ungültige Werte.');
    $beforeStmt = $pdo->prepare('SELECT * FROM time_user_settings WHERE user_id=:id');
    $beforeStmt->execute([':id'=>$userId]);
    $before = $beforeStmt->fetch() ?: null;
    $now = nowUtc();
    $pdo->prepare("INSERT INTO time_user_settings (user_id,target_minutes_per_day,annual_leave_days,carryover_leave_days,contractual_minutes_per_week,hourly_wage,updated_by,updated_at) VALUES (:id,:daily,:annual,:carryover,:weekly,:wage,:actor,:updated) ON DUPLICATE KEY UPDATE target_minutes_per_day=VALUES(target_minutes_per_day),annual_leave_days=VALUES(annual_leave_days),carryover_leave_days=VALUES(carryover_leave_days),contractual_minutes_per_week=VALUES(contractual_minutes_per_week),hourly_wage=VALUES(hourly_wage),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)")->execute([':id'=>$userId,':daily'=>$dailyMinutes,':annual'=>$annual,':carryover'=>$carryover,':weekly'=>$weeklyMinutes,':wage'=>$hourlyWage,':actor'=>(int)$actor['id'],':updated'=>$now]);
    ttAudit($actor, 'employee_settings_save', 'user_settings', $userId, $before, ['target_minutes_per_day'=>$dailyMinutes,'annual_leave_days'=>$annual,'carryover_leave_days'=>$carryover,'contractual_minutes_per_week'=>$weeklyMinutes,'hourly_wage'=>$hourlyWage]);
    apiJson(['ok'=>true,'user_id'=>$userId,'target_minutes_per_day'=>$dailyMinutes,'annual_leave_days'=>$annual,'carryover_leave_days'=>$carryover,'contractual_minutes_per_week'=>$weeklyMinutes,'hourly_wage'=>$hourlyWage]);
}

function handleTimeMotoImport(array $actor): never
{
    $permissions=ttPermissions($actor);if(!$permissions['can_edit_all'])apiError(403,'Nur Christian darf TimeMoto-Daten importieren.');if(empty($_FILES['file'])||!is_uploaded_file($_FILES['file']['tmp_name']))apiError(400,'Bitte eine TimeMoto-Excel-Datei auswählen.');$file=$_FILES['file'];if((int)$file['error']!==UPLOAD_ERR_OK)apiError(400,'TimeMoto-Datei konnte nicht gelesen werden.');$pdo=db();$rows=timeMotoRows((string)$file['tmp_name']);$entries=0;$leaves=0;$skipped=0;$now=nowUtc();foreach($rows as $row){$first=trim((string)($row['Vorname']??''));$last=trim((string)($row['Nachname']??''));$date=trim((string)($row['Datum']??''));$user=findTimeMotoUser($pdo,$first,$last);if(!$user||$date===''){$skipped++;continue;}$remark=ttText((string)($row['Remarks']??''));$hours=ttParseMinutes((string)($row['Abwesenheit']??''));$leaveType=str_contains($remark,'ueberstunden')?'zeitausgleich':(str_contains($remark,'urlaub')?'urlaub':(str_contains($remark,'krank')?'krank':''));if($leaveType!==''&&$hours!==null&&$hours>0){$key=hash('sha256', 'leave|'.(int)$user['id'].'|'.$date.'|'.$remark.'|'.$hours);$stmt=$pdo->prepare('INSERT IGNORE INTO time_leave_requests (user_id,leave_type,starts_on,ends_on,hours,note,status,source,external_key,requested_by,decided_by,decided_at,created_at,updated_at) VALUES (:user,:type,:from,:to,:hours,:note,\'approved\',\'timemoto_import\',:key,:requester,:decider,:decided,:created,:updated)');$stmt->execute([':user'=>(int)$user['id'],':type'=>$leaveType,':from'=>substr((string)ttDateTime($date),0,10),':to'=>substr((string)ttDateTime($date),0,10),':hours'=>$hours/60,':note'=>'TimeMoto: '.ucfirst($leaveType),':key'=>$key,':requester'=>(int)$actor['id'],':decider'=>(int)$actor['id'],':decided'=>$now,':created'=>$now,':updated'=>$now]);if($stmt->rowCount()){$leaves++;ttAudit($actor,'timemoto_import','leave_request',(int)$pdo->lastInsertId(),null,['user_id'=>(int)$user['id'],'leave_type'=>$leaveType,'hours'=>$hours/60,'source_key'=>$key]);}continue;}$started=ttDateTime($date,(string)($row['Hinein']??''));$ended=ttDateTime($date,(string)($row['Hinaus']??''));if(!$started||!$ended){$skipped++;continue;}$worked=ttParseMinutes((string)($row['Gearbeitete Stunden']??''))??ttParseMinutes((string)($row['Dauer']??''))??0;$planned=ttParseMinutes((string)($row['Geplant']??''));if($planned!==null&&$planned>0){$settingQ=$pdo->prepare('SELECT target_minutes_per_day FROM time_user_settings WHERE user_id=:id');$settingQ->execute([':id'=>(int)$user['id']]);$currentTarget=$settingQ->fetchColumn();if($currentTarget===false||$currentTarget===null){$pdo->prepare('INSERT INTO time_user_settings (user_id,target_minutes_per_day,updated_by,updated_at) VALUES (:id,:target,:actor,:updated) ON DUPLICATE KEY UPDATE target_minutes_per_day=VALUES(target_minutes_per_day),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)')->execute([':id'=>(int)$user['id'],':target'=>$planned,':actor'=>(int)$actor['id'],':updated'=>$now]);}}$balance=ttParseMinutes((string)($row['Saldo']??''))??($planned===null?0:$worked-$planned);$key=hash('sha256',implode('|',[(int)$user['id'],$date,$started,$ended,$worked,$balance]));$stmt=$pdo->prepare('INSERT IGNORE INTO time_entries (user_id,started_at,ended_at,break_minutes,worked_minutes,planned_minutes,balance_minutes,source,external_key,note,created_by,updated_by,created_at,updated_at) VALUES (:user,:started,:ended,0,:worked,:planned,:balance,\'timemoto_import\',:key,:note,:creator,:updater,:created,:updated)');$stmt->execute([':user'=>(int)$user['id'],':started'=>$started,':ended'=>$ended,':worked'=>$worked,':planned'=>$planned,':balance'=>$balance,':key'=>$key,':note'=>trim((string)($row['Comments']??'')),':creator'=>(int)$actor['id'],':updater'=>(int)$actor['id'],':created'=>$now,':updated'=>$now]);if($stmt->rowCount()){$entries++;ttAudit($actor,'timemoto_import','time_entry',(int)$pdo->lastInsertId(),null,['user_id'=>(int)$user['id'],'source_key'=>$key]);}}
    apiJson(['ok'=>true,'entries_imported'=>$entries,'leave_imported'=>$leaves,'skipped'=>$skipped,'rows'=>count($rows)]);
}

