<?php
/**
 * Demo-Daten – SV-Netzwerk Prüfportal
 *
 * GET  /api/demo.php           – Prüft ob Demo-Daten vorhanden sind
 * POST /api/demo.php           – Legt realistische Demo-Daten an
 * POST /api/demo.php?reset=1   – Löscht Demo-Daten und legt sie neu an
 *
 * Legt an:
 *  - 3 Benutzer (Admin + 2 Prüfer)
 *  - Gebäude A (Hauptgebäude)
 *  - 3 Etagen mit je 3-4 Räumen
 *  - 2-4 Fenster pro Raum
 *  - 2 Flügel pro Fenster (Dreh-Kipp)
 *  - Beispiel-Inspektionsdaten (gemischt: ok, Mängel, offen)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$reset  = isset($_GET['reset']) && $_GET['reset'] === '1';

if ($method === 'GET') {
    handleStatus();
} elseif ($method === 'POST') {
    // Demo-Endpunkt ist öffentlich zugänglich (nur Setup)
    handleCreate($reset);
} else {
    apiError(405, 'Methode nicht erlaubt.');
}

function handleStatus(): never
{
    $status = ['demo_data_exists' => false, 'building_count' => 0, 'sash_count' => 0, 'user_count' => 0];
    try {
        $status['building_count'] = (int) db()->query("SELECT COUNT(*) FROM buildings WHERE project_id=1")->fetchColumn();
        $status['sash_count']     = (int) db()->query("SELECT COUNT(*) FROM window_sashes WHERE deleted_at IS NULL")->fetchColumn();
        $status['user_count']     = (int) db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $status['demo_data_exists'] = $status['building_count'] > 0;
    } catch (Throwable $e) {
        $status['error'] = $e->getMessage();
    }
    apiJson($status);
}

function handleCreate(bool $reset): never
{
    if ($reset) {
        try {
            // Soft-delete aller Fenster und Flügel
            db()->exec("UPDATE window_sashes SET deleted_at=UTC_TIMESTAMP() WHERE deleted_at IS NULL");
            db()->exec("UPDATE windows SET deleted_at=UTC_TIMESTAMP() WHERE deleted_at IS NULL");
            db()->exec("DELETE FROM rooms");
            db()->exec("DELETE FROM floors");
            db()->exec("DELETE FROM buildings WHERE project_id=1");
        } catch (Throwable $e) {
            apiError(503, 'Reset fehlgeschlagen: ' . $e->getMessage());
        }
    }

    $results = [];

    // ── Benutzer ──────────────────────────────────────────────────────────────
    $users = [
        [env('SEED_ADMIN_EMAIL',    'admin@sv-schuett.eu'),    env('SEED_ADMIN_NAME',    'Administrator'),   'administrator', env('SEED_ADMIN_PASSWORD',    '')],
        [env('SEED_PRUEFER1_EMAIL', 'pruefer1@sv-schuett.eu'), env('SEED_PRUEFER1_NAME', 'Prüfer 1'),        'pruefer',       env('SEED_PRUEFER1_PASSWORD',  '')],
        [env('SEED_PRUEFER2_EMAIL', 'pruefer2@sv-schuett.eu'), env('SEED_PRUEFER2_NAME', 'Prüfer 2'),        'pruefer',       env('SEED_PRUEFER2_PASSWORD',  '')],
    ];
    $createdUsers = [];
    foreach ($users as [$email, $name, $role, $pw]) {
        if ($pw === '') {
            $results[] = "Benutzer $email übersprungen: Kein Passwort in .env konfiguriert (SEED_*_PASSWORD).";
            continue;
        }
        try {
            $hash = hashPassword($pw);
            db()->prepare(
                'INSERT INTO users (email,full_name,role,password_hash,is_active,created_at,updated_at)
                 VALUES (:e,:n,:r,:h,1,:now,:now2)
                 ON DUPLICATE KEY UPDATE full_name=:n2, role=:r2, is_active=1, updated_at=:now3'
            )->execute([':e'=>$email,':n'=>$name,':r'=>$role,':h'=>$hash,':now'=>nowUtc(),':now2'=>nowUtc(),':n2'=>$name,':r2'=>$role,':now3'=>nowUtc()]);
            $uid = db()->query("SELECT id FROM users WHERE email='" . addslashes($email) . "'")->fetchColumn();
            $createdUsers[$email] = (int) $uid;
            $results[] = "Benutzer: $email";
        } catch (Throwable $e) {
            $results[] = "Benutzer $email übersprungen: " . $e->getMessage();
        }
    }

    $pruefer1Email = env('SEED_PRUEFER1_EMAIL', 'pruefer1@sv-schuett.eu');
    $pruefer2Email = env('SEED_PRUEFER2_EMAIL', 'pruefer2@sv-schuett.eu');
    $pruefer1Id   = $createdUsers[$pruefer1Email] ?? 1;
    $pruefer2Id   = $createdUsers[$pruefer2Email] ?? 1;
    $pruefer1Name = env('SEED_PRUEFER1_NAME', 'Prüfer 1');
    $pruefer2Name = env('SEED_PRUEFER2_NAME', 'Prüfer 2');

    // ── Gebäude ───────────────────────────────────────────────────────────────
    try {
        $bid = (int) db()->query("SELECT id FROM buildings WHERE name='Hauptgebäude A' AND project_id=1")->fetchColumn();
        if (!$bid) {
            db()->prepare('INSERT INTO buildings (project_id,name,code,notes,sort_order,created_at,updated_at) VALUES (1,:n,:c,:notes,10,:now,:now2)')
                ->execute([':n'=>'Hauptgebäude A',':c'=>'HGA',':notes'=>'Hauptgebäude, 1. Dienstsitz BMVg',':now'=>nowUtc(),':now2'=>nowUtc()]);
            $bid = (int) db()->lastInsertId();
        }
        $results[] = "Gebäude: Hauptgebäude A (ID $bid)";
    } catch (Throwable $e) {
        apiError(503, 'Gebäude konnte nicht angelegt werden: ' . $e->getMessage());
    }

    // ── Etagen ────────────────────────────────────────────────────────────────
    $floorDefs = [
        ['Erdgeschoss',    0, 10],
        ['1. Obergeschoss', 1, 20],
        ['2. Obergeschoss', 2, 30],
    ];
    $floorIds = [];
    foreach ($floorDefs as [$fname, $level, $sort]) {
        try {
            $fid = (int) db()->query("SELECT id FROM floors WHERE building_id=$bid AND name='$fname'")->fetchColumn();
            if (!$fid) {
                db()->prepare('INSERT INTO floors (building_id,name,level,sort_order,created_at,updated_at) VALUES (:bid,:n,:lv,:so,:now,:now2)')
                    ->execute([':bid'=>$bid,':n'=>$fname,':lv'=>$level,':so'=>$sort,':now'=>nowUtc(),':now2'=>nowUtc()]);
                $fid = (int) db()->lastInsertId();
            }
            $floorIds[] = $fid;
            $results[] = "Etage: $fname (ID $fid)";
        } catch (Throwable $e) {
            $results[] = "Etage $fname übersprungen: " . $e->getMessage();
        }
    }

    // ── Räume ─────────────────────────────────────────────────────────────────
    $roomDefs = [
        // [floor_index, name, room_number]
        [0, 'Empfang / Pforte',        'EG-01'],
        [0, 'Konferenzraum 1',         'EG-02'],
        [0, 'Büro Hausmeister',        'EG-03'],
        [0, 'Flur West',               'EG-04'],
        [1, 'Büro 1.01',               '1.01'],
        [1, 'Büro 1.02',               '1.02'],
        [1, 'Besprechungsraum 1.03',   '1.03'],
        [1, 'Flur 1. OG',              '1-Flur'],
        [2, 'Büro 2.01',               '2.01'],
        [2, 'Büro 2.02',               '2.02'],
        [2, 'Lager / Archiv',          '2.03'],
    ];
    $roomIds = [];
    foreach ($roomDefs as [$fi, $rname, $rnum]) {
        if (!isset($floorIds[$fi])) continue;
        $fid = $floorIds[$fi];
        try {
            $rid = (int) db()->query("SELECT id FROM rooms WHERE floor_id=$fid AND room_number='$rnum'")->fetchColumn();
            if (!$rid) {
                db()->prepare('INSERT INTO rooms (floor_id,name,room_number,sort_order,created_at,updated_at) VALUES (:fid,:n,:rn,10,:now,:now2)')
                    ->execute([':fid'=>$fid,':n'=>$rname,':rn'=>$rnum,':now'=>nowUtc(),':now2'=>nowUtc()]);
                $rid = (int) db()->lastInsertId();
            }
            $roomIds[] = ['id'=>$rid,'floor'=>$fi,'name'=>$rname,'number'=>$rnum,'floor_id'=>$fid];
            $results[] = "Raum: $rnum $rname (ID $rid)";
        } catch (Throwable $e) {
            $results[] = "Raum $rnum übersprungen: " . $e->getMessage();
        }
    }

    // ── Fenster und Flügel ────────────────────────────────────────────────────
    $windowCounter = 1;
    $openingTypes  = ['Dreh-Kipp', 'Dreh', 'Kipp', 'Dreh-Kipp'];
    $glassTypes    = ['ESG 6mm', 'VSG 8.8mm', 'ESG 8mm', 'VSG 6.4mm', 'ESG 4mm'];
    $ratings       = [
        'ohne festgestellten Handlungsbedarf',
        'geringfuegige Auffaelligkeit',
        'Wartung oder Nachstellung erforderlich',
        'Instandsetzung erforderlich',
        'ohne festgestellten Handlungsbedarf',
    ];
    $statuses = ['abgeschlossen','abgeschlossen','in Bearbeitung','nicht begonnen','abgeschlossen'];

    $floorNames    = ['Erdgeschoss','1. Obergeschoss','2. Obergeschoss'];
    $buildingLabel = 'Hauptgebäude A';

    foreach ($roomIds as $room) {
        $windowCount = ($room['number'] === '1-Flur' || $room['number'] === 'EG-04') ? 2 : 3;
        $floorLabel  = $floorNames[$room['floor']] ?? '';
        for ($w = 1; $w <= $windowCount; $w++) {
            $wnum = sprintf('F-%03d', $windowCounter++);
            try {
                // Prüfen ob Fenster schon vorhanden
                $existWid = (int) db()->query("SELECT id FROM windows WHERE room_id={$room['id']} AND window_number='$wnum' AND deleted_at IS NULL")->fetchColumn();
                if (!$existWid) {
                    $recId = 'BMVG-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                    db()->prepare(
                        'INSERT INTO windows (project_id,room_id,record_id,window_number,room_label,room_number,building_label,floor_label,status,created_at,updated_at)
                         VALUES (1,:rid,:recid,:wnum,:rlabel,:rnum,:blabel,:flabel,\'nicht begonnen\',:now,:now2)'
                    )->execute([
                        ':rid'=>$room['id'],':recid'=>$recId,':wnum'=>$wnum,
                        ':rlabel'=>$room['name'],':rnum'=>$room['number'],
                        ':blabel'=>$buildingLabel,':flabel'=>$floorLabel,
                        ':now'=>nowUtc(),':now2'=>nowUtc(),
                    ]);
                    $wid = (int) db()->lastInsertId();
                } else {
                    $wid = $existWid;
                }
            } catch (Throwable $e) {
                $results[] = "Fenster $wnum übersprungen: " . $e->getMessage();
                continue;
            }

            // 2 Flügel anlegen
            $sashDefs = [
                ['Flügel Links', 'links', $openingTypes[array_rand($openingTypes)]],
                ['Flügel Rechts', 'rechts', $openingTypes[array_rand($openingTypes)]],
            ];
            $sashNum = 1;
            foreach ($sashDefs as [$slabel, $pos, $otype]) {
                // Status und Daten variieren
                $si      = ($windowCounter + $sashNum) % count($statuses);
                $status  = $statuses[$si];
                $rating  = $ratings[$si];
                $glass   = $glassTypes[$si % count($glassTypes)];
                $w_mm    = 600 + ($si * 120);
                $h_mm    = 900 + ($si * 100);
                $prueferName = ($sashNum % 2 === 0) ? $pruefer2Name : $pruefer1Name;
                $prueferId   = ($sashNum % 2 === 0) ? $pruefer2Id   : $pruefer1Id;

                $hasDefect = in_array($rating, ['Wartung oder Nachstellung erforderlich','Instandsetzung erforderlich'], true);
                $progress  = in_array($status, ['abgeschlossen','freigegeben'], true) ? 100
                           : ($status === 'in Bearbeitung' ? 55 : 0);

                $formData = [
                    'status'              => $status,
                    'sash_label'          => $slabel,
                    'opening_type'        => $otype,
                    'position'            => $pos,
                    'inspection_date'     => date('Y-m-d', strtotime('-' . rand(1,20) . ' days')),
                    'inspector_name'      => $prueferName,
                    'glass_structure'     => $glass,
                    'glazing_width_mm'    => $w_mm,
                    'glazing_height_mm'   => $h_mm,
                    'hinge_condition'     => $hasDefect ? 'Scharniere locker' : 'einwandfrei',
                    'fitting_condition'   => $hasDefect ? 'Verschleiß erkennbar' : 'einwandfrei',
                    'hinge_fastening_loose' => $hasDefect,
                    'fitting_defect'      => $hasDefect,
                    'overall_rating'      => $rating,
                    'recommended_action'  => $hasDefect ? 'Nachstellen und erneut prüfen' : 'Keine Maßnahme erforderlich',
                    'notes'               => '',
                ];

                try {
                    $existSid = (int) db()->query("SELECT id FROM window_sashes WHERE window_id=$wid AND sash_number=$sashNum AND deleted_at IS NULL")->fetchColumn();
                    if (!$existSid) {
                        db()->prepare(
                            'INSERT INTO window_sashes
                             (window_id,sash_number,sash_label,opening_type,position,status,form_data,
                              progress_percent,has_defect,overall_rating,inspector_id,inspector_name,
                              inspected_at,completed_at,created_at,updated_at)
                             VALUES (:wid,:snum,:slabel,:otype,:pos,:status,:fd,:pp,:hd,:orating,:iid,:iname,:iat,:cat,:now,:now2)'
                        )->execute([
                            ':wid'=>$wid,':snum'=>$sashNum,':slabel'=>$slabel,
                            ':otype'=>$otype,':pos'=>$pos,':status'=>$status,
                            ':fd'=>json_encode($formData, JSON_UNESCAPED_UNICODE),
                            ':pp'=>$progress,':hd'=>$hasDefect?1:0,':orating'=>$rating,
                            ':iid'=>$prueferId,':iname'=>$prueferName,
                            ':iat'=>$status !== 'nicht begonnen' ? nowUtc() : null,
                            ':cat'=>$status === 'abgeschlossen' ? nowUtc() : null,
                            ':now'=>nowUtc(),':now2'=>nowUtc(),
                        ]);
                    }
                } catch (Throwable $e) {
                    $results[] = "Flügel $slabel für $wnum übersprungen: " . $e->getMessage();
                }
                $sashNum++;
            }

            // Fensterstatus ableiten
            try {
                $doneCount = (int) db()->query("SELECT COUNT(*) FROM window_sashes WHERE window_id=$wid AND status IN ('abgeschlossen','freigegeben') AND deleted_at IS NULL")->fetchColumn();
                $totalCount = (int) db()->query("SELECT COUNT(*) FROM window_sashes WHERE window_id=$wid AND deleted_at IS NULL")->fetchColumn();
                $winStatus = $totalCount > 0 && $doneCount === $totalCount ? 'Pruefung abgeschlossen'
                           : ($doneCount > 0 ? 'in Bearbeitung' : 'nicht begonnen');
                db()->prepare('UPDATE windows SET status=:s WHERE id=:id')->execute([':s'=>$winStatus,':id'=>$wid]);
            } catch (Throwable) {}

            $results[] = "Fenster $wnum mit 2 Flügeln angelegt";
        }
    }

    apiJson([
        'ok'      => true,
        'message' => 'Demo-Daten erfolgreich angelegt.',
        'results' => $results,
    ], 201);
}
