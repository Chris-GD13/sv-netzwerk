<?php
/**
 * Spesen und Reisekosten im Prüfportal.
 *
 * Die Daten bleiben in der Portal-Datenbank. Belege werden außerhalb des
 * Webroots abgelegt, sofern SPESEN_DIR gesetzt ist, und nur nach Anmeldung
 * über diesen Endpunkt ausgeliefert.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
ensureSpesenSchema();

$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? 'summary');
$cronToken = trim((string)($_SERVER['HTTP_X_SPESEN_CRON'] ?? ''));
$configuredCronToken = env('SPESEN_CRON_TOKEN', env('APP_SECRET', ''));
$isCron = $action === 'monthly_export' && $method === 'POST' && $cronToken !== '' && $configuredCronToken !== '' && hash_equals($configuredCronToken, $cronToken);
$actor = $isCron ? ['id'=>0,'email'=>'','full_name'=>'Spesen-Automatik','role'=>'administrator','is_active'=>1] : requireAuth();
if (!$isCron && strcasecmp((string)($actor['email'] ?? ''), env('SPESEN_OWNER_EMAIL', 'cw@sv-schuett.eu')) !== 0) {
    apiError(404, 'Unbekannter Spesen-Endpunkt.');
}

match (true) {
    $method === 'GET' && $action === 'access' => handleSpesenAccess($actor),
    $method === 'GET' && $action === 'summary' => handleSummary($actor),
    $method === 'GET' && $action === 'trip' => handleTrip($actor),
    $method === 'GET' && $action === 'export_csv' => handleExportCsv($actor),
    $method === 'GET' && $action === 'receipt' => handleReceipt($actor),
    $method === 'POST' && $action === 'save_trip' => handleSaveTrip($actor),
    $method === 'POST' && $action === 'save_expense' => handleSaveExpense($actor),
    $method === 'POST' && $action === 'submit_trip' => handleSubmitTrip($actor),
    $method === 'POST' && $action === 'decide_trip' => handleDecideTrip($actor),
    $method === 'POST' && $action === 'delete_expense' => handleDeleteExpense($actor),
    $method === 'POST' && $action === 'upload_receipt' => handleUploadReceipt($actor),
    $method === 'POST' && $action === 'monthly_export' && ($isCron || spesenApprover($actor)) => handleMonthlyExport($actor, $isCron),
    default => apiError(404, 'Unbekannter Spesen-Endpunkt.'),
};

function ensureSpesenSchema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS spesen_trips (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(190) NOT NULL,
        purpose VARCHAR(500) NULL,
        origin VARCHAR(190) NULL,
        destination VARCHAR(190) NULL,
        intermediate_stops TEXT NULL,
        country_code CHAR(2) NOT NULL DEFAULT 'DE',
        starts_on DATE NOT NULL,
        ends_on DATE NOT NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        cost_center VARCHAR(120) NULL,
        notes TEXT NULL,
        per_diem_full DECIMAL(8,2) NOT NULL DEFAULT 28.00,
        per_diem_partial DECIMAL(8,2) NOT NULL DEFAULT 14.00,
        mileage_km DECIMAL(10,2) NOT NULL DEFAULT 0,
        mileage_rate DECIMAL(8,3) NOT NULL DEFAULT 0.300,
        meal_breakfast TINYINT(1) NOT NULL DEFAULT 0,
        meal_lunch TINYINT(1) NOT NULL DEFAULT 0,
        meal_dinner TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(16) NOT NULL DEFAULT 'draft',
        decision_note TEXT NULL,
        decided_by INT UNSIGNED NULL,
        decided_at DATETIME NULL,
        submitted_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_spesen_trips_user_dates (user_id, starts_on, ends_on),
        KEY idx_spesen_trips_status (status),
        CONSTRAINT fk_spesen_trips_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS spesen_expenses (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        trip_id BIGINT UNSIGNED NULL,
        user_id INT UNSIGNED NOT NULL,
        expense_date DATE NOT NULL,
        category VARCHAR(40) NOT NULL DEFAULT 'sonstiges',
        merchant VARCHAR(190) NULL,
        description VARCHAR(500) NULL,
        amount DECIMAL(12,2) NOT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'EUR',
        exchange_rate DECIMAL(14,6) NOT NULL DEFAULT 1,
        amount_eur DECIMAL(12,2) NOT NULL,
        vat_rate DECIMAL(5,2) NULL,
        payment_method VARCHAR(32) NOT NULL DEFAULT 'privat',
        mileage_km DECIMAL(10,2) NULL,
        mileage_rate DECIMAL(8,3) NULL,
        receipt_path VARCHAR(500) NULL,
        receipt_name VARCHAR(190) NULL,
        receipt_mime VARCHAR(120) NULL,
        receipt_size INT UNSIGNED NULL,
        receipt_sha256 CHAR(64) NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'draft',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_spesen_expenses_user_date (user_id, expense_date),
        KEY idx_spesen_expenses_trip (trip_id),
        CONSTRAINT fk_spesen_expenses_trip FOREIGN KEY (trip_id) REFERENCES spesen_trips(id) ON DELETE SET NULL,
        CONSTRAINT fk_spesen_expenses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS spesen_exports (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        period_start DATE NOT NULL,
        period_end DATE NOT NULL,
        filename VARCHAR(190) NOT NULL,
        recipient VARCHAR(255) NOT NULL,
        cc VARCHAR(255) NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        sent_at DATETIME NULL,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_spesen_export_period (period_start, period_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function spesenText(mixed $value, int $max = 500): string
{
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? $text;
    return mb_substr($text, 0, $max, 'UTF-8');
}

function spesenDate(mixed $value, string $fallback = ''): string
{
    $value = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}

function spesenMoney(mixed $value, float $default = 0.0): float
{
    $value = str_replace(',', '.', trim((string)$value));
    return is_numeric($value) ? round((float)$value, 2) : $default;
}

function spesenRate(mixed $value, float $default): float
{
    $value = str_replace(',', '.', trim((string)$value));
    return is_numeric($value) ? max(0, min(100000, (float)$value)) : $default;
}

function spesenApprover(array $user): bool
{
    return in_array((string)($user['role'] ?? ''), ['administrator', 'projektleiter'], true);
}

function spesenCanSee(array $actor, int $ownerId): bool
{
    return spesenApprover($actor) || (int)$actor['id'] === $ownerId;
}

function spesenTrip(PDO $pdo, int $id): ?array
{
    $q = $pdo->prepare('SELECT t.*, u.full_name AS user_name, u.email AS user_email FROM spesen_trips t JOIN users u ON u.id=t.user_id WHERE t.id=:id LIMIT 1');
    $q->execute([':id' => $id]);
    $row = $q->fetch();
    if (!$row) return null;
    $e = $pdo->prepare('SELECT * FROM spesen_expenses WHERE trip_id=:id ORDER BY expense_date ASC,id ASC');
    $e->execute([':id' => $id]);
    $row['expenses'] = $e->fetchAll();
    $row['estimate'] = spesenEstimate($row);
    return $row;
}

function spesenEstimate(array $trip): array
{
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$trip['starts_on']);
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$trip['ends_on']);
    $days = (!$from || !$to || $to < $from) ? 0 : (int)$from->diff($to)->days + 1;
    $fullDays = max(0, $days - 2);
    $partialDays = $days > 0 ? min(2, $days) : 0;
    $full = (float)($trip['per_diem_full'] ?? 28);
    $partial = (float)($trip['per_diem_partial'] ?? 14);
    $mealDeduction = $full * (0.2 * (int)($trip['meal_breakfast'] ?? 0) + 0.4 * (int)($trip['meal_lunch'] ?? 0) + 0.4 * (int)($trip['meal_dinner'] ?? 0));
    $perDiem = max(0, ($fullDays * $full) + ($partialDays * $partial) - ($mealDeduction * max(1, $days)));
    $km = max(0, (float)($trip['mileage_km'] ?? 0));
    $kmRate = max(0, (float)($trip['mileage_rate'] ?? 0.30));
    $expenses = 0.0;
    foreach (($trip['expenses'] ?? []) as $expense) $expenses += (float)($expense['amount_eur'] ?? 0);
    return ['days'=>$days,'full_days'=>$fullDays,'partial_days'=>$partialDays,'per_diem'=>round($perDiem,2),'mileage'=>round($km*$kmRate,2),'expenses'=>round($expenses,2),'total'=>round($perDiem+($km*$kmRate)+$expenses,2)];
}

function spesenTripPayload(array $row): array
{
    $row['estimate'] = spesenEstimate($row);
    return $row;
}

function handleSpesenAccess(array $actor): never
{
    apiJson(['ok' => true, 'visible' => true, 'owner' => (string)($actor['email'] ?? '')]);
}

function handleSummary(array $actor): never
{
    $pdo = db();
    $scope = spesenApprover($actor) ? '' : 'WHERE t.user_id=' . (int)$actor['id'];
    $q = $pdo->query("SELECT t.*,u.full_name AS user_name,
        (SELECT COUNT(*) FROM spesen_expenses e WHERE e.trip_id=t.id) AS expense_count,
        (SELECT COALESCE(SUM(e.amount_eur),0) FROM spesen_expenses e WHERE e.trip_id=t.id) AS expense_total
        FROM spesen_trips t JOIN users u ON u.id=t.user_id $scope ORDER BY t.updated_at DESC,t.id DESC LIMIT 100");
    $trips = [];
    foreach ($q->fetchAll() as $row) $trips[] = spesenTripPayload($row);
    $expenseScope = spesenApprover($actor) ? '' : 'AND user_id=' . (int)$actor['id'];
    $month = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m');
    $m = $pdo->prepare("SELECT COALESCE(SUM(amount_eur),0) FROM spesen_expenses WHERE expense_date LIKE :month $expenseScope");
    $m->execute([':month' => $month . '%']);
    $monthTotal = (float)$m->fetchColumn();
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM spesen_trips WHERE status='submitted'" . (spesenApprover($actor) ? '' : ' AND user_id=' . (int)$actor['id']))->fetchColumn();
    $drafts = (int)$pdo->query("SELECT COUNT(*) FROM spesen_trips WHERE status IN ('draft','rejected')" . (spesenApprover($actor) ? '' : ' AND user_id=' . (int)$actor['id']))->fetchColumn();
    apiJson(['ok'=>true,'user'=>$actor,'is_approver'=>spesenApprover($actor),'trips'=>$trips,'stats'=>['month_total'=>round($monthTotal,2),'pending'=>$pending,'drafts'=>$drafts]]);
}

function handleTrip(array $actor): never
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) apiError(400, 'Reise-ID fehlt.');
    $row = spesenTrip(db(), $id);
    if (!$row || !spesenCanSee($actor, (int)$row['user_id'])) apiError(404, 'Reise nicht gefunden.');
    apiJson(['ok'=>true,'trip'=>$row,'can_edit'=>spesenApprover($actor)||(int)$row['user_id']===(int)$actor['id'] && in_array($row['status'],['draft','rejected'],true)]);
}

function handleSaveTrip(array $actor): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
    $body = requestBody();
    $id = (int)($body['id'] ?? 0);
    $title = spesenText($body['title'] ?? '', 190);
    $starts = spesenDate($body['starts_on'] ?? '');
    $ends = spesenDate($body['ends_on'] ?? '');
    if ($title === '' || $starts === '' || $ends === '') apiError(400, 'Bezeichnung sowie Beginn und Ende sind erforderlich.');
    if ($ends < $starts) apiError(400, 'Das Ende darf nicht vor dem Beginn liegen.');
    $pdo = db();
    $now = nowUtc();
    $data = [
        ':title'=>$title, ':purpose'=>spesenText($body['purpose']??'',500), ':origin'=>spesenText($body['origin']??'',190),
        ':destination'=>spesenText($body['destination']??'',190), ':stops'=>spesenText($body['intermediate_stops']??'',1000),
        ':country'=>strtoupper(preg_match('/^[A-Za-z]{2}$/', (string)($body['country_code']??'DE')) ? (string)$body['country_code'] : 'DE'),
        ':starts'=>$starts, ':ends'=>$ends, ':start_time'=>spesenText($body['start_time']??'',8) ?: null, ':end_time'=>spesenText($body['end_time']??'',8) ?: null,
        ':cost_center'=>spesenText($body['cost_center']??'',120), ':notes'=>spesenText($body['notes']??'',2000),
        ':full'=>spesenMoney($body['per_diem_full']??28,28), ':partial'=>spesenMoney($body['per_diem_partial']??14,14),
        ':km'=>spesenRate($body['mileage_km']??0,0), ':km_rate'=>spesenRate($body['mileage_rate']??0.30,0.30),
        ':breakfast'=>!empty($body['meal_breakfast'])?1:0, ':lunch'=>!empty($body['meal_lunch'])?1:0, ':dinner'=>!empty($body['meal_dinner'])?1:0,
    ];
    if ($id > 0) {
        $q=$pdo->prepare('SELECT * FROM spesen_trips WHERE id=:id LIMIT 1');$q->execute([':id'=>$id]);$existing=$q->fetch();
        if (!$existing || !spesenCanSee($actor,(int)$existing['user_id'])) apiError(404,'Reise nicht gefunden.');
        if ((int)$existing['user_id']!==(int)$actor['id'] && !spesenApprover($actor)) apiError(403,'Diese Reise darf nicht geändert werden.');
        if (!spesenApprover($actor) && !in_array($existing['status'],['draft','rejected'],true)) apiError(409,'Eingereichte oder freigegebene Reisen sind gesperrt.');
        $data[':id']=$id;$data[':updated']=$now;
        $pdo->prepare('UPDATE spesen_trips SET title=:title,purpose=:purpose,origin=:origin,destination=:destination,intermediate_stops=:stops,country_code=:country,starts_on=:starts,ends_on=:ends,start_time=:start_time,end_time=:end_time,cost_center=:cost_center,notes=:notes,per_diem_full=:full,per_diem_partial=:partial,mileage_km=:km,mileage_rate=:km_rate,meal_breakfast=:breakfast,meal_lunch=:lunch,meal_dinner=:dinner,status=IF(status="rejected","draft",status),updated_at=:updated WHERE id=:id')->execute($data);
    } else {
        $data[':user']=(int)$actor['id'];$data[':created']=$now;$data[':updated']=$now;
        $pdo->prepare('INSERT INTO spesen_trips(user_id,title,purpose,origin,destination,intermediate_stops,country_code,starts_on,ends_on,start_time,end_time,cost_center,notes,per_diem_full,per_diem_partial,mileage_km,mileage_rate,meal_breakfast,meal_lunch,meal_dinner,status,created_at,updated_at) VALUES(:user,:title,:purpose,:origin,:destination,:stops,:country,:starts,:ends,:start_time,:end_time,:cost_center,:notes,:full,:partial,:km,:km_rate,:breakfast,:lunch,:dinner,"draft",:created,:updated)')->execute($data);
        $id=(int)$pdo->lastInsertId();
    }
    apiJson(['ok'=>true,'trip'=>spesenTrip($pdo,$id)]);
}

function handleSaveExpense(array $actor): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
    $body=requestBody();$id=(int)($body['id']??0);$tripId=(int)($body['trip_id']??0);
    $date=spesenDate($body['expense_date']??'');$amount=spesenMoney($body['amount']??0);
    if ($date===''||$amount<=0) apiError(400,'Datum und Betrag sind erforderlich.');
    $pdo=db();$trip=null;
    if($tripId>0){$trip=spesenTrip($pdo,$tripId);if(!$trip||!spesenCanSee($actor,(int)$trip['user_id']))apiError(404,'Reise nicht gefunden.');if((int)$trip['user_id']!==(int)$actor['id'])apiError(403,'Beleg darf nur vom Ersteller geändert werden.');if(!in_array($trip['status'],['draft','rejected'],true))apiError(409,'Belege einer eingereichten oder freigegebenen Reise sind gesperrt.');}
    $existing=null;
    if($id>0){$q=$pdo->prepare('SELECT * FROM spesen_expenses WHERE id=:id LIMIT 1');$q->execute([':id'=>$id]);$existing=$q->fetch();if(!$existing||(int)$existing['user_id']!==(int)$actor['id'])apiError(404,'Beleg nicht gefunden.');if(!in_array($existing['status'],['draft','rejected'],true))apiError(409,'Dieser Beleg ist gesperrt.');}
    $currency=strtoupper(preg_match('/^[A-Za-z]{3}$/',(string)($body['currency']??'EUR'))?(string)$body['currency']:'EUR');$rate=spesenRate($body['exchange_rate']??1,1);if($rate<=0)$rate=1;
    $amountEur=round($amount*$rate,2);$km=spesenRate($body['mileage_km']??0,0);$kmRate=spesenRate($body['mileage_rate']??0.30,0.30);
    $fields=[':trip'=>$tripId>0?$tripId:null,':user'=>(int)$actor['id'],':date'=>$date,':category'=>spesenText($body['category']??'sonstiges',40),':merchant'=>spesenText($body['merchant']??'',190),':description'=>spesenText($body['description']??'',500),':amount'=>$amount,':currency'=>$currency,':rate'=>$rate,':eur'=>$amountEur,':vat'=>($body['vat_rate']??'')===''?null:spesenRate($body['vat_rate'],0),':payment'=>spesenText($body['payment_method']??'privat',32),':km'=>$km>0?$km:null,':km_rate'=>$km>0?$kmRate:null,':now'=>nowUtc()];
    if($id>0){$fields[':id']=$id;$pdo->prepare('UPDATE spesen_expenses SET trip_id=:trip,expense_date=:date,category=:category,merchant=:merchant,description=:description,amount=:amount,currency=:currency,exchange_rate=:rate,amount_eur=:eur,vat_rate=:vat,payment_method=:payment,mileage_km=:km,mileage_rate=:km_rate,status=IF(status="rejected","draft",status),updated_at=:now WHERE id=:id')->execute($fields);}
    else{$pdo->prepare('INSERT INTO spesen_expenses(trip_id,user_id,expense_date,category,merchant,description,amount,currency,exchange_rate,amount_eur,vat_rate,payment_method,mileage_km,mileage_rate,status,created_at,updated_at) VALUES(:trip,:user,:date,:category,:merchant,:description,:amount,:currency,:rate,:eur,:vat,:payment,:km,:km_rate,"draft",:now,:now)')->execute($fields);$id=(int)$pdo->lastInsertId();}
    apiJson(['ok'=>true,'expense_id'=>$id,'expense'=>$pdo->query('SELECT * FROM spesen_expenses WHERE id='.(int)$id)->fetch()]);
}

function handleSubmitTrip(array $actor): never
{
    $id=(int)(requestBody()['id']??0);if($id<=0)apiError(400,'Reise-ID fehlt.');$pdo=db();$q=$pdo->prepare('SELECT * FROM spesen_trips WHERE id=:id');$q->execute([':id'=>$id]);$trip=$q->fetch();if(!$trip||(int)$trip['user_id']!==(int)$actor['id'])apiError(404,'Reise nicht gefunden.');if(!in_array($trip['status'],['draft','rejected'],true))apiError(409,'Diese Reise wurde bereits eingereicht oder freigegeben.');$now=nowUtc();$pdo->prepare('UPDATE spesen_trips SET status="submitted",submitted_at=:now,updated_at=:now,decision_note=NULL,decided_by=NULL,decided_at=NULL WHERE id=:id')->execute([':now'=>$now,':id'=>$id]);$pdo->prepare('UPDATE spesen_expenses SET status="submitted",updated_at=:now WHERE trip_id=:id AND user_id=:user AND status IN ("draft","rejected")')->execute([':now'=>$now,':id'=>$id,':user'=>(int)$actor['id']]);apiJson(['ok'=>true,'trip'=>spesenTrip($pdo,$id)]);
}

function handleDecideTrip(array $actor): never
{
    if(!spesenApprover($actor))apiError(403,'Nur Administratoren oder Projektleiter dürfen Reisen freigeben.');$body=requestBody();$id=(int)($body['id']??0);$decision=(string)($body['decision']??'');if($id<=0||!in_array($decision,['approved','rejected'],true))apiError(400,'Entscheidung fehlt.');$pdo=db();$q=$pdo->prepare('SELECT * FROM spesen_trips WHERE id=:id');$q->execute([':id'=>$id]);$trip=$q->fetch();if(!$trip)apiError(404,'Reise nicht gefunden.');if($trip['status']!=='submitted')apiError(409,'Nur eingereichte Reisen können entschieden werden.');$now=nowUtc();$note=spesenText($body['decision_note']??'',2000);$pdo->prepare('UPDATE spesen_trips SET status=:status,decision_note=:note,decided_by=:actor,decided_at=:now,updated_at=:now WHERE id=:id')->execute([':status'=>$decision,':note'=>$note,':actor'=>(int)$actor['id'],':now'=>$now,':id'=>$id]);$pdo->prepare('UPDATE spesen_expenses SET status=:status,updated_at=:now WHERE trip_id=:id')->execute([':status'=>$decision,':now'=>$now,':id'=>$id]);apiJson(['ok'=>true,'trip'=>spesenTrip($pdo,$id)]);
}

function handleDeleteExpense(array $actor): never
{
    $id=(int)(requestBody()['id']??0);if($id<=0)apiError(400,'Beleg-ID fehlt.');$pdo=db();$q=$pdo->prepare('SELECT * FROM spesen_expenses WHERE id=:id');$q->execute([':id'=>$id]);$row=$q->fetch();if(!$row||(int)$row['user_id']!==(int)$actor['id'])apiError(404,'Beleg nicht gefunden.');if(!in_array($row['status'],['draft','rejected'],true))apiError(409,'Dieser Beleg ist gesperrt.');if($row['receipt_path']&&is_file((string)$row['receipt_path']))@unlink((string)$row['receipt_path']);$pdo->prepare('DELETE FROM spesen_expenses WHERE id=:id')->execute([':id'=>$id]);apiJson(['ok'=>true]);
}

function spesenStorageDir(): string
{
    $configured=trim(env('SPESEN_DIR',''));$base=$configured!==''?$configured:dirname(__DIR__,3).'/var/spesen';if(!is_dir($base)&&!@mkdir($base,0770,true))apiError(503,'Spesenablage ist nicht verfügbar.');if(!is_writable($base))apiError(503,'Spesenablage ist nicht beschreibbar.');return rtrim($base,'/');
}

function handleUploadReceipt(array $actor): never
{
    $expenseId=(int)($_POST['expense_id']??0);if($expenseId<=0)apiError(400,'Beleg-ID fehlt.');$pdo=db();$q=$pdo->prepare('SELECT * FROM spesen_expenses WHERE id=:id');$q->execute([':id'=>$expenseId]);$row=$q->fetch();if(!$row||(int)$row['user_id']!==(int)$actor['id'])apiError(404,'Beleg nicht gefunden.');if(!in_array($row['status'],['draft','rejected'],true))apiError(409,'Der Beleg ist bereits eingereicht oder freigegeben.');$file=$_FILES['receipt']??null;if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)apiError(400,'Bitte einen lesbaren Beleg auswählen.');if((int)($file['size']??0)>15*1024*1024)apiError(400,'Der Beleg darf höchstens 15 MB groß sein.');$tmp=(string)($file['tmp_name']??'');$mime=(string)(mime_content_type($tmp)?:'application/octet-stream');$allowed=['application/pdf','image/jpeg','image/png','image/webp'];if(!in_array($mime,$allowed,true))apiError(400,'Erlaubt sind PDF, JPG, PNG oder WEBP.');$hash=hash_file('sha256',$tmp);$safe=preg_replace('/[^A-Za-z0-9._-]+/','_',basename((string)($file['name']??'beleg')));$dir=spesenStorageDir().'/'.(int)$actor['id'];if(!is_dir($dir)&&!@mkdir($dir,0770,true))apiError(503,'Belegablage konnte nicht angelegt werden.');$target=$dir.'/'.$expenseId.'_'.bin2hex(random_bytes(8)).'_'.$safe;if(!move_uploaded_file($tmp,$target))apiError(503,'Beleg konnte nicht gespeichert werden.');if($row['receipt_path']&&is_file((string)$row['receipt_path']))@unlink((string)$row['receipt_path']);$pdo->prepare('UPDATE spesen_expenses SET receipt_path=:path,receipt_name=:name,receipt_mime=:mime,receipt_size=:size,receipt_sha256=:sha,updated_at=:now WHERE id=:id')->execute([':path'=>$target,':name'=>$safe,':mime'=>$mime,':size'=>(int)$file['size'],':sha'=>$hash,':now'=>nowUtc(),':id'=>$expenseId]);$extracted=[];$analysisError='';try{$extracted=spesenAnalyzeReceipt($target,$mime,$safe);}catch(Throwable $e){$analysisError=$e->getMessage();}apiJson(['ok'=>true,'expense_id'=>$expenseId,'receipt_name'=>$safe,'extracted'=>$extracted,'analysis_error'=>$analysisError]);
}

function spesenAnalyzeReceipt(string $path,string $mime,string $name): array
{
    $key=trim(env('OPENAI_API_KEY',''));if($key==='')throw new RuntimeException('Automatische Belegauslesung ist nicht konfiguriert.');$bytes=@file_get_contents($path);if($bytes===false||$bytes==='')throw new RuntimeException('Beleg konnte für die automatische Auslesung nicht gelesen werden.');$mime=trim(explode(';',$mime,2)[0]);$type=str_starts_with($mime,'image/')?'input_image':'input_file';$item=['type'=>$type,($type==='input_image'?'image_url':'file_data')=>'data:'.$mime.';base64,'.base64_encode($bytes),'detail'=>'high'];$payload=['model'=>env('OPENAI_MODEL','gpt-5.4-mini'),'instructions'=>'Du liest einen deutschen Geschäftsbeleg für eine Spesenabrechnung aus. Antworte ausschließlich als JSON. Erfinde keine Angaben. Unklare Werte bleiben leer. Schema: {"expense_date":"YYYY-MM-DD oder leer","merchant":"string","amount":0,"currency":"EUR","vat_rate":0,"description":"string","category":"hotel|verpflegung|fahrt|parkmaut|bewirtung|sonstiges","confidence":"high|medium|low","notes":"string"}. amount ist der Bruttobetrag als Zahl.','input'=>[['role'=>'user','content'=>[['type'=>'input_text','text'=>'Lies diesen Beleg vollständig aus und gib die Felder für das Spesenformular zurück. Dateiname: '.$name],$item]]],'text'=>['format'=>['type'=>'json_object']],'max_output_tokens'=>800];$r=spesenGraphHttp('POST','https://api.openai.com/v1/responses',['Content-Type: application/json','Authorization: Bearer '.$key],json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$j=json_decode($r['body'],true);if($r['status']<200||$r['status']>=300)throw new RuntimeException((string)($j['error']['message']??'Automatische Belegauslesung fehlgeschlagen.'));$text=trim((string)($j['output_text']??''));if($text==='')foreach(($j['output']??[])as$o)foreach(($o['content']??[])as$c)if(($c['type']??'')==='output_text')$text.=(string)($c['text']??'');$data=json_decode(trim($text),true);if(!is_array($data))throw new RuntimeException('Die automatische Belegauslesung lieferte kein verwertbares Ergebnis.');return['expense_date'=>preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($data['expense_date']??''))?(string)$data['expense_date']:'','merchant'=>mb_substr(trim((string)($data['merchant']??'')),0,190),'amount'=>(float)($data['amount']??0),'currency'=>strtoupper(mb_substr(trim((string)($data['currency']??'EUR')),0,3)),'vat_rate'=>(float)($data['vat_rate']??0),'description'=>mb_substr(trim((string)($data['description']??'')),0,500),'category'=>in_array(($data['category']??''),['hotel','verpflegung','fahrt','parkmaut','bewirtung','sonstiges'],true)?$data['category']:'sonstiges','confidence'=>in_array(($data['confidence']??''),['high','medium','low'],true)?$data['confidence']:'low','notes'=>mb_substr(trim((string)($data['notes']??'')),0,500)];
}
function handleReceipt(array $actor): never
{
    $id=(int)($_GET['id']??0);$pdo=db();$q=$pdo->prepare('SELECT * FROM spesen_expenses WHERE id=:id');$q->execute([':id'=>$id]);$row=$q->fetch();if(!$row||!spesenCanSee($actor,(int)$row['user_id'])||!$row['receipt_path']||!is_file((string)$row['receipt_path']))apiError(404,'Belegdatei nicht gefunden.');header('Content-Type: '.($row['receipt_mime']?:'application/octet-stream'));header('Content-Length: '.filesize((string)$row['receipt_path']));header('Content-Disposition: inline; filename="'.str_replace('"','',basename((string)($row['receipt_name']?:'beleg'))).'"');readfile((string)$row['receipt_path']);exit;
}

function spesenGraphHttp(string $method, string $url, array $headers = [], mixed $body = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false || $error !== '') throw new RuntimeException('Microsoft-Verbindung fehlgeschlagen.');
    return ['status' => $status, 'body' => (string)$response];
}

function spesenGraphToken(): string
{
    $tenant = env('MS_TENANT_ID', env('M365_TENANT_ID', ''));
    $client = env('MS_CLIENT_ID', env('M365_CLIENT_ID', ''));
    $secret = env('MS_CLIENT_SECRET', env('M365_CLIENT_SECRET', ''));
    if ($tenant === '' || $client === '' || $secret === '') throw new RuntimeException('Microsoft-Verbindung für den DATEV-Versand ist nicht vollständig eingerichtet.');
    $response = spesenGraphHttp('POST', msLoginBase() . '/' . rawurlencode($tenant) . '/oauth2/v2.0/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => $client, 'client_secret' => $secret,
        'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
    ]));
    $json = json_decode($response['body'], true);
    if ($response['status'] !== 200 || empty($json['access_token'])) throw new RuntimeException('Microsoft-Anmeldung für den DATEV-Versand ist fehlgeschlagen.');
    return (string)$json['access_token'];
}

function spesenRecipient(string $address): array
{
    if (!filter_var($address, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ungültige DATEV-Empfängeradresse.');
    return ['emailAddress' => ['address' => $address]];
}

function spesenSendDatevMail(string $filename, string $zipBytes, string $periodLabel, string $to, string $cc): void
{
    $sender = trim(env('SPESEN_EXPORT_SENDER', env('MAIL_FROM', 'ws@sv-schuett.eu')));
    if (!filter_var($sender, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ungültige Absenderadresse für den DATEV-Versand.');
    $message = [
        'subject' => 'DATEV-Spesenexport ' . $periodLabel,
        'body' => ['contentType' => 'HTML', 'content' => '<p>Automatischer Spesenexport für ' . htmlspecialchars($periodLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.</p>'],
        'toRecipients' => [spesenRecipient($to)],
        'attachments' => [[
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $filename,
            'contentType' => 'application/zip',
            'contentBytes' => base64_encode($zipBytes),
        ]],
    ];
    if ($cc !== '') $message['ccRecipients'] = [spesenRecipient($cc)];
    $response = spesenGraphHttp('POST', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender) . '/sendMail', [
        'Authorization: Bearer ' . spesenGraphToken(), 'Content-Type: application/json',
    ], json_encode(['message' => $message, 'saveToSentItems' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($response['status'] < 200 || $response['status'] >= 300) throw new RuntimeException('DATEV-Spesenexport konnte nicht versendet werden.');
}

function spesenDatevMoney(float $value): string
{
    return number_format(round($value, 2), 2, ',', '');
}

function spesenDatevDate(?string $value): string
{
    if (!$value) return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date ? $date->format('d.m.Y') : '';
}

function spesenDatevRows(PDO $pdo, string $from, string $to): array
{
    $q = $pdo->prepare("SELECT t.*, u.full_name AS user_name, u.email AS user_email
        FROM spesen_trips t JOIN users u ON u.id=t.user_id
        WHERE t.status='approved' AND t.starts_on <= :to AND t.ends_on >= :from
        ORDER BY t.starts_on, t.id");
    $q->execute([':from' => $from, ':to' => $to]);
    $trips = $q->fetchAll();
    $e = $pdo->prepare("SELECT e.*, t.title AS trip_title, t.starts_on, t.ends_on, t.start_time, t.end_time,
        t.origin, t.destination, t.intermediate_stops, t.purpose, u.full_name AS user_name, u.email AS user_email
        FROM spesen_expenses e LEFT JOIN spesen_trips t ON t.id=e.trip_id JOIN users u ON u.id=e.user_id
        WHERE e.status='approved' AND e.expense_date BETWEEN :from AND :to ORDER BY e.expense_date, e.id");
    $e->execute([':from' => $from, ':to' => $to]);
    $expenses = $e->fetchAll();
    $header = ['Startdatum','Uhrzeit','Enddatum','Uhrzeit','Startort','Zielorte','Nummer','Person','Personalnummer','Name','Kostenstelle Name','Kostenstelle Nummer','Kostenträger Name','Kostenträger Nummer','Datum Beleg','End Datum Beleg','Belegdatum','Art','Beschreibung','Belegnummer','Bruttobetrag','Nettobetrag','Vorsteuer','Kurs','Benutzerdefinierter Kurs','Betrag in Fremdwährung','Fremdwährung','Zahlungsart','Debitor','Kreditor','Vorsteuer %','Kontenrahmen','Anzahl Nächte','Anzahl Belege','Anzahl Fahrten Privatfahrzeug','Kilometer Privatfahrzeug','Kommentar'];
    $rows = [$header];
    $base = static function(array $r, string $art, float $gross, float $net, float $vat, string $account, int $nights = 0, int $receipts = 0, float $km = 0): array {
        $start = (string)($r['starts_on'] ?? $r['expense_date'] ?? '');
        $end = (string)($r['ends_on'] ?? $r['expense_date'] ?? $start);
        $person = (string)($r['user_name'] ?? '');
        $personal = str_pad((string)((int)($r['user_id'] ?? 0)), 4, '0', STR_PAD_LEFT);
        $destination = trim((string)($r['destination'] ?? ''));
        $country = strtoupper((string)($r['country_code'] ?? 'DE')) === 'DE' ? ' Deutschland' : '';
        $destination .= $country;
        $tripNo = !empty($r['trip_id']) ? 'Reise Nr. ' . (int)$r['trip_id'] : '';
        $vatRate = (float)($r['vat_rate'] ?? 0);
        return [$start ? spesenDatevDate($start) : '', (string)($r['start_time'] ?? ''), $end ? spesenDatevDate($end) : '', (string)($r['end_time'] ?? ''), (string)($r['origin'] ?? ''), $destination, $tripNo, $person, $personal, (string)(($r['purpose'] ?? '') ?: ($r['title'] ?? '')), '', (string)($r['cost_center'] ?? ''), '', '', spesenDatevDate($r['expense_date'] ?? $start), '', spesenDatevDate($r['expense_date'] ?? $start), $art, (string)($r['description'] ?? ''), '', spesenDatevMoney($gross), spesenDatevMoney($net), spesenDatevMoney($vat), '', '', '', '', (string)($r['payment_method'] ?? ''), $account, env('SPESEN_CREDITOR', '70553'), $vatRate > 0 ? rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.') . '%' : '0%', 'SKR04', (string)$nights, (string)$receipts, '0', $km > 0 ? spesenDatevMoney($km) : '0', (string)($r['notes'] ?? '')];
    };
    foreach ($trips as $trip) {
        $trip['expense_date'] = $trip['starts_on'];
        $estimate = spesenEstimate($trip);
        $perDiem = (float)$estimate['per_diem'];
        if ($perDiem > 0) $rows[] = $base($trip, 'Tagespauschale', $perDiem, $perDiem, 0, env('SPESEN_ACCOUNT_PER_DIEM', '6664'), max(0, (int)$estimate['days'] - 1));
        $nights = max(0, (int)$estimate['days'] - 1);
        $overnight = $nights * (float)env('SPESEN_OVERNIGHT_FLAT', '20');
        if ($overnight > 0) $rows[] = $base($trip, 'Übernachtungspauschale', $overnight, $overnight, 0, env('SPESEN_ACCOUNT_OVERNIGHT', '6660'), $nights);
        if ((float)$trip['mileage_km'] > 0) $rows[] = $base($trip, 'Privatfahrzeug', (float)$trip['mileage_km'] * (float)$trip['mileage_rate'], (float)$trip['mileage_km'] * (float)$trip['mileage_rate'], 0, env('SPESEN_ACCOUNT_MILEAGE', '4660'), 0, 0, (float)$trip['mileage_km']);
    }
    foreach ($expenses as $expense) {
        $gross = (float)$expense['amount_eur'];
        $vatRate = (float)($expense['vat_rate'] ?? 0);
        $net = $vatRate > 0 ? round($gross / (1 + $vatRate / 100), 2) : $gross;
        $vat = round($gross - $net, 2);
        $expense['expense_date'] = $expense['expense_date'];
        $expense['description'] = trim((string)$expense['description']);
        $expense['vat_rate'] = $vatRate;
        $rows[] = $base($expense, match ((string)$expense['category']) {
            'hotel' => 'Übernachtung', 'verpflegung' => 'Verpflegung', 'fahrt' => 'Fahrt', 'parkmaut' => 'Parken/Maut', 'bewirtung' => 'Bewirtung', default => 'Sonstiges',
        }, $gross, $net, $vat, env('SPESEN_ACCOUNT_EXPENSES', '4650'), 0, 1, (float)($expense['mileage_km'] ?? 0));
    }
    return $rows;
}

function handleMonthlyExport(array $actor, bool $isCron): never
{
    if (!$isCron && !spesenApprover($actor)) apiError(403, 'Nur Administratoren oder Projektleiter dürfen DATEV-Exporte auslösen.');
    $zone = new DateTimeZone('Europe/Berlin');
    $today = new DateTimeImmutable('now', $zone);
    $requested = spesenText((requestBody()['month'] ?? $_GET['month'] ?? ''), 7);
    if ($requested !== '' && !preg_match('/^\\d{4}-\\d{2}$/', $requested)) apiError(400, 'Monat muss im Format JJJJ-MM angegeben werden.');
    $period = $requested !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $requested . '-01', $zone) : $today->modify('first day of this month');
    if (!$period) apiError(400, 'Ungültiger Exportmonat.');
    $periodEnd = $period->modify('last day of this month');
    if ($isCron && $today->format('Y-m-d') !== $periodEnd->format('Y-m-d')) apiJson(['ok'=>true,'skipped'=>true,'reason'=>'not_month_end','date'=>$today->format('Y-m-d')]);
    $from = $period->format('Y-m-d'); $to = $periodEnd->format('Y-m-d');
    $pdo = db();
    $toAddress = trim(env('SPESEN_EXPORT_TO', 'ms@sv-schuett.eu'));
    $ccAddress = trim(env('SPESEN_EXPORT_CC', 'cw@sv-schuett.eu'));
    $existing = $pdo->prepare('SELECT * FROM spesen_exports WHERE period_start=:from AND period_end=:to LIMIT 1');
    $existing->execute([':from'=>$from, ':to'=>$to]);
    $row = $existing->fetch();
    if ($row && $row['status'] === 'sent') apiJson(['ok'=>true,'already_sent'=>true,'period'=>$period->format('Y-m'),'filename'=>$row['filename']]);
    if ($row) $exportId = (int)$row['id'];
    else { $pdo->prepare('INSERT INTO spesen_exports(period_start,period_end,filename,recipient,cc,status,created_at) VALUES(:from,:to,:name,:to,:cc,"pending",:now)')->execute([':from'=>$from,':to'=>$to,':name'=>'pending',':to'=>$toAddress,':cc'=>$ccAddress,':now'=>nowUtc()]); $exportId=(int)$pdo->lastInsertId(); }
    try {
        if (!class_exists('ZipArchive')) throw new RuntimeException('PHP-Zip ist auf dem Portalserver nicht verfügbar.');
        $rows = spesenDatevRows($pdo, $from, $to);
        $csv = "\xEF\xBB\xBF"; $fp = fopen('php://temp', 'w+'); foreach ($rows as $line) fputcsv($fp, $line, ';', '"'); rewind($fp); $csv .= stream_get_contents($fp); fclose($fp);
        $stamp = $today->format('Ymd'); $baseName = 'EXCEL_lang_' . $period->format('Ymd') . '-' . $periodEnd->format('Ymd') . '_Spesenfuchs_' . $stamp . '-' . $exportId;
        $csvName = $baseName . '.csv'; $txtName = $baseName . '.txt'; $zipName = $baseName . '.zip';
        $tmp = tempnam(sys_get_temp_dir(), 'spesen-'); $zip = new ZipArchive(); if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('DATEV-ZIP konnte nicht erstellt werden.'); $zip->addFromString($csvName, $csv); $zip->addFromString($txtName, $csv); $zip->close(); $zipBytes = (string)file_get_contents($tmp); @unlink($tmp);
        spesenSendDatevMail($zipName, $zipBytes, $period->format('m/Y'), $toAddress, $ccAddress);
        $pdo->prepare('UPDATE spesen_exports SET filename=:name,status="sent",sent_at=:sent,error_message=NULL WHERE id=:id')->execute([':name'=>$zipName,':sent'=>nowUtc(),':id'=>$exportId]);
        apiJson(['ok'=>true,'period'=>$period->format('Y-m'),'filename'=>$zipName,'recipient'=>$toAddress,'cc'=>$ccAddress,'rows'=>max(0,count($rows)-1)]);
    } catch (Throwable $error) {
        $pdo->prepare('UPDATE spesen_exports SET status="error",error_message=:error WHERE id=:id')->execute([':error'=>mb_substr($error->getMessage(),0,2000),'id'=>$exportId]);
        apiError(500, $error->getMessage());
    }
}

function handleExportCsv(array $actor): never
{
    $from=spesenDate($_GET['from']??'2000-01-01','2000-01-01');$to=spesenDate($_GET['to']??'2099-12-31','2099-12-31');$status=spesenText($_GET['status']??'',16);$pdo=db();$where=['e.expense_date BETWEEN :from AND :to'];$params=[':from'=>$from,':to'=>$to];if(!spesenApprover($actor)){$where[]='e.user_id=:user';$params[':user']=(int)$actor['id'];}if(in_array($status,['draft','submitted','approved','rejected'],true)){$where[]='e.status=:status';$params[':status']=$status;}$q=$pdo->prepare('SELECT e.*,t.title AS trip_title,u.full_name AS user_name FROM spesen_expenses e LEFT JOIN spesen_trips t ON t.id=e.trip_id JOIN users u ON u.id=e.user_id WHERE '.implode(' AND ',$where).' ORDER BY e.expense_date,e.id');$q->execute($params);$rows=$q->fetchAll();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="spesen-export-'.date('Y-m-d').'.csv"');echo "\xEF\xBB\xBF"; $out=fopen('php://output','w');fputcsv($out,['Datum','Mitarbeiter','Reise','Kategorie','Händler','Beschreibung','Betrag Original','Währung','Kurs','Betrag EUR','MwSt %','Zahlungsart','Status','Beleg'], ';');foreach($rows as $r)fputcsv($out,[$r['expense_date'],$r['user_name'],$r['trip_title'],$r['category'],$r['merchant'],$r['description'],$r['amount'],$r['currency'],$r['exchange_rate'],$r['amount_eur'],$r['vat_rate'],$r['payment_method'],$r['status'],$r['receipt_name']], ';');fclose($out);exit;
}
