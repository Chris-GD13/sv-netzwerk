<?php
/**
 * Referenzprojekt in Produktionsdatenbank einfügen
 * 
 * Wird über setup.php aufgerufen: POST /intern/api/setup.php?action=seed_reference&key=<SETUP_KEY>
 * Erstellt Projekt 2 mit realistischen Testdaten (810+ Fenster).
 */

declare(strict_types=1);

function seedReferenceProject(): array
{
    $pdo = db();
    
    // Check if reference project already exists
    $exists = $pdo->query("SELECT COUNT(*) FROM projects WHERE id = 2")->fetchColumn();
    if ((int)$exists > 0) {
        // Delete existing reference data to re-seed
        $pdo->exec("DELETE FROM windows WHERE project_id = 2");
        $pdo->exec("DELETE FROM buildings WHERE project_id = 2");
        $pdo->exec("DELETE FROM projects WHERE id = 2");
    }
    
    // 1. Create project
    $pdo->exec("INSERT INTO projects (id, project_code, title, object_name, address, planned_window_count, is_active)
        VALUES (2, 'referenz-testprojekt', 'REFERENZ: Fensterbeschlagsprüfung (Testdaten)',
                'Testprojekt – BMVg Dienstsitz Bonn (Demo)', 'Fontainengraben 150, 53123 Bonn (fiktiv)', 810, 1)");
    
    // 2. Buildings
    $buildings = [
        ['Verwaltungsgebäude Nord', 'VGN', 'Hauptverwaltung, Bj. 2005, 1.OG 2012 saniert'],
        ['Verwaltungsgebäude Süd', 'VGS', 'Empfang + Großraumbüros, Bj. 1998'],
        ['Konferenzzentrum', 'KON', 'Tagungsräume, Bj. 2018, modernster Bestand'],
        ['Technisches Zentrum', 'TZ', 'Werkstätten/Technik, Bj. 1985, ältester Bestand – KRITISCH'],
        ['Nebengebäude West', 'NGW', 'Hausverwaltung, Bj. 1990, Austausch geplant 2026'],
        ['Pforte und Wache', 'PW', 'Eingangsgebäude, Bj. 2010'],
        ['Kantine und Sozialgebäude', 'KAS', 'Verpflegung, Bj. 2001, Küche 2015 erneuert'],
    ];
    
    $buildingIds = [];
    $stmt = $pdo->prepare("INSERT INTO buildings (project_id, name, code, notes, sort_order) VALUES (2, ?, ?, ?, ?)");
    foreach ($buildings as $i => $b) {
        $stmt->execute([$b[0], $b[1], $b[2], $i + 1]);
        $buildingIds[$b[1]] = (int)$pdo->lastInsertId();
    }
    
    // 3. Floors
    $floorDefs = [
        'VGN' => [['Untergeschoss', -1], ['Erdgeschoss', 0], ['1. Obergeschoss', 1], ['2. Obergeschoss', 2], ['3. Obergeschoss', 3]],
        'VGS' => [['Untergeschoss', -1], ['Erdgeschoss', 0], ['1. Obergeschoss', 1], ['2. Obergeschoss', 2]],
        'KON' => [['Erdgeschoss', 0], ['1. Obergeschoss', 1]],
        'TZ'  => [['Untergeschoss', -1], ['Erdgeschoss', 0], ['1. Obergeschoss', 1], ['2. Obergeschoss', 2], ['3. Obergeschoss', 3], ['4. Obergeschoss', 4]],
        'NGW' => [['Untergeschoss', -1], ['Erdgeschoss', 0], ['1. Obergeschoss', 1]],
        'PW'  => [['Erdgeschoss', 0], ['1. Obergeschoss', 1]],
        'KAS' => [['Erdgeschoss', 0], ['1. Obergeschoss', 1]],
    ];
    
    $floorIds = []; // floorIds['VGN'][0] = id for EG
    $stmtF = $pdo->prepare("INSERT INTO floors (building_id, name, level, sort_order) VALUES (?, ?, ?, ?)");
    foreach ($floorDefs as $bCode => $floors) {
        $floorIds[$bCode] = [];
        foreach ($floors as $i => $f) {
            $stmtF->execute([$buildingIds[$bCode], $f[0], $f[1], $i]);
            $floorIds[$bCode][$f[1]] = (int)$pdo->lastInsertId();
        }
    }
    
    // 4. Rooms and Windows
    $roomTypes = [
        'buero' => ['Büro', 2],
        'grossraum' => ['Großraumbüro', 5],
        'bespr' => ['Besprechungsraum', 3],
        'konf' => ['Konferenzraum', 4],
        'flur' => ['Flur', 1],
        'treppe' => ['Treppenhaus', 1],
        'wc' => ['WC', 1],
        'technik' => ['Technikraum', 0],
        'server' => ['Serverraum', 0],
        'lager' => ['Lager', 1],
        'teekueche' => ['Teeküche', 1],
        'werkstatt' => ['Werkstatt', 3],
        'kantine' => ['Kantine', 8],
        'kueche' => ['Küche', 3],
        'empfang' => ['Empfang', 3],
        'aufenthalt' => ['Aufenthaltsraum', 2],
        'archiv' => ['Archiv', 0],
    ];
    
    // Building → Floor → Room definitions
    $structure = [
        'VGN' => [
            -1 => [['Heizungsraum','technik',1],['Lager UG','lager',1],['Archiv UG','archiv',0]],
            0 => [['Empfang','empfang',3],['Büro 1','buero',2],['Büro 2','buero',2],['Büro 3','buero',2],
                  ['Büro 4','buero',2],['Büro 5','buero',2],['Büro 6','buero',2],['WC Herren','wc',1],
                  ['WC Damen','wc',1],['Teeküche EG','teekueche',1],['Archiv EG','archiv',1],['Treppenhaus','treppe',1]],
            1 => [['Büro 201','buero',2],['Büro 202','buero',2],['Besprechung 1','bespr',3],['Büro 204','buero',2],
                  ['Büro 205','buero',2],['Büro 206','buero',2],['Büro 207','buero',2],['Büro 208','buero',2],
                  ['Büro 209','buero',2],['Büro 210','buero',2],['WC 1.OG H','wc',1],['WC 1.OG D','wc',1],
                  ['Serverraum','server',0],['Treppenhaus','treppe',1]],
            2 => [['Konferenzraum','konf',4],['Büro 302','buero',2],['Sekretariat','buero',1],
                  ['Büro 304','buero',2],['Büro 305','buero',2],['WC 2.OG','wc',1],['Treppenhaus','treppe',1]],
            3 => [['Büro Abtlg.','buero',2],['Büro 402','buero',2],['Büro 403','buero',2],
                  ['Verschlusssache','lager',0],['WC 3.OG','wc',1]],
        ],
        'VGS' => [
            -1 => [['Archiv UG','archiv',1],['Technik UG','technik',0],['Lager','lager',1]],
            0 => [['Empfangshalle','empfang',4],['Großraumbüro 1','grossraum',6],['Großraumbüro 2','grossraum',5],
                  ['WC EG H','wc',1],['WC EG D','wc',1],['Teeküche','teekueche',1],['Flur EG','flur',1]],
            1 => [['Büro 101','buero',2],['Büro 102','buero',2],['Büro 103','buero',2],['Büro 104','buero',2],
                  ['Büro 105','buero',2],['Besprechung','bespr',3],['WC 1.OG','wc',1],['Flur','flur',1]],
            2 => [['Büro 201','buero',2],['Büro 202','buero',2],['Büro 203','buero',2],['Büro 204','buero',2],
                  ['Büro 205','buero',2],['Büro 206','buero',2],['WC 2.OG','wc',1],['Flur','flur',1]],
        ],
        'KON' => [
            0 => [['Foyer','empfang',4],['Konferenz 1','konf',5],['Konferenz 2','konf',4],['Technik','technik',0],['WC EG','wc',1]],
            1 => [['Seminar 1','konf',4],['Seminar 2','konf',4],['Seminar 3','bespr',3],['Aufenthalt','aufenthalt',2],['WC 1.OG','wc',1]],
        ],
        'TZ' => [
            -1 => [['Heizungsraum','technik',1],['Elektro','technik',0],['Lager Ersatzteile','lager',1],
                   ['Werkstatt Metall','werkstatt',3],['Lager Beschläge','lager',0]],
            0 => [['Empfang TZ','empfang',2],['Werkstatt Holz','werkstatt',4],['Werkstatt Kunststoff','werkstatt',4],
                  ['Materialausgabe','lager',2],['Büro Werkstattleiter','buero',2],['WC/Umkleide','wc',2],['Flur EG','flur',1]],
            1 => [['Büro Technik 1','buero',2],['Büro Technik 2','buero',2],['Prüflabor','buero',3],
                  ['Messraum','buero',1],['Serverraum','server',0],['Besprechung TZ','bespr',3],['Teeküche','teekueche',1]],
            2 => [['Büro 201','buero',2],['Büro 202','buero',2],['Büro 203','buero',2],['Büro 204','buero',2],
                  ['Archiv','archiv',1],['WC 2.OG','wc',1],['Flur','flur',1]],
            3 => [['Büro 301','buero',2],['Büro 302','buero',2],['Büro 303','buero',2],['Lager','lager',1],['Flur','flur',1]],
            4 => [['Technikzentrale','technik',1],['Lüftung','technik',1],['Büro 401','buero',2],['Flur','flur',1]],
        ],
        'NGW' => [
            -1 => [['Lager UG','lager',1],['Technik','technik',0]],
            0 => [['Hausverwaltung','buero',2],['Poststelle','buero',2],['Lager EG','lager',1],['Flur','flur',1]],
            1 => [['Büro 101','buero',2],['Büro 102','buero',2],['Archiv','archiv',1],['Flur','flur',1]],
        ],
        'PW' => [
            0 => [['Pförtnerloge','empfang',3],['Warteraum','aufenthalt',2],['Kontrollraum','buero',2],['WC','wc',1]],
            1 => [['Büro Leiter','buero',2],['Büro 102','buero',2],['Teeküche','teekueche',1]],
        ],
        'KAS' => [
            0 => [['Kantine','kantine',10],['Küche','kueche',4],['Ausgabe','lager',2],['WC EG','wc',1],['Flur','flur',1]],
            1 => [['Aufenthalt 1','aufenthalt',3],['Aufenthalt 2','aufenthalt',3],['WC 1.OG','wc',1]],
        ],
    ];
    
    $hersteller = ['Roto Frank','Siegenia-Aubi','Winkhaus','Maco','GU-BKS','Schüco','Weru'];
    $beschlaege = ['Roto NT Designo','Roto NX','Titan AF','activPilot','Multi-Matic','UNI-JET','AvanTec'];
    $glastypen = ['2-fach WSG','3-fach WSG','2-fach VSG','ESG'];
    $statuses = ['nicht begonnen','nicht begonnen','nicht begonnen','in Bearbeitung','in Bearbeitung',
                 'abgeschlossen','abgeschlossen','abgeschlossen','Nachprüfung','Mangel festgestellt',
                 'Austausch empfohlen','nicht zugänglich'];
    $lagen = ['Nord','Süd','Ost','West'];
    $typen = ['Dreh-Kipp','Dreh-Kipp','Dreh','Kipp','Fest'];
    
    // Deterministic random
    $seed = 42;
    $rand = function() use (&$seed) { $seed = ($seed * 1664525 + 1013904223) & 0x7FFFFFFF; return $seed / 0x7FFFFFFF; };
    $randInt = function($min, $max) use ($rand) { return $min + (int)($rand() * ($max - $min + 1)); };
    $pick = function($arr) use ($rand) { return $arr[(int)($rand() * count($arr))]; };
    
    // Building-specific hardware mapping
    $bHardware = [
        'VGN' => [['Roto Frank','Roto NT Designo',2005],['Siegenia-Aubi','Titan AF',2012],['Winkhaus','activPilot',2005]],
        'VGS' => [['Roto Frank','Roto NT Designo',1998],['Siegenia-Aubi','Titan AF',2005]],
        'KON' => [['Schüco','AvanTec',2018]],
        'TZ'  => [['Maco','Multi-Matic',1985],['GU-BKS','UNI-JET',2000]],
        'NGW' => [['Weru','Eigenentwicklung',1990]],
        'PW'  => [['GU-BKS','UNI-JET B',2010]],
        'KAS' => [['Winkhaus','activPilot',2001],['Winkhaus','activPilot',2015]],
    ];
    
    $stmtR = $pdo->prepare("INSERT INTO rooms (floor_id, name, room_number, sort_order) VALUES (?, ?, ?, ?)");
    $stmtW = $pdo->prepare("INSERT INTO windows (project_id, room_id, record_id, window_number, 
        building_label, floor_label, room_label, room_number, status, form_data, created_at) 
        VALUES (2, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmtS = $pdo->prepare("INSERT INTO window_sashes (window_id, sash_number, sash_label, opening_type, status, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())");
    
    $windowCount = 0;
    $sashCount = 0;
    $roomNum = 1;
    
    foreach ($structure as $bCode => $floors) {
        foreach ($floors as $level => $rooms) {
            if (!isset($floorIds[$bCode][$level])) continue;
            $fId = $floorIds[$bCode][$level];
            $floorName = '';
            foreach ($floorDefs[$bCode] as $fd) { if ($fd[1] === $level) $floorName = $fd[0]; }
            
            foreach ($rooms as $ri => $roomDef) {
                $rName = $roomDef[0];
                $rType = $roomDef[1];
                $windowsInRoom = $roomDef[2];
                $rNumber = $bCode . '-' . str_pad((string)(($level+1)*100 + $ri + 1), 3, '0', STR_PAD_LEFT);
                
                $stmtR->execute([$fId, $rName, $rNumber, $ri]);
                $roomId = (int)$pdo->lastInsertId();
                
                // Create windows for this room
                $hw = $pick($bHardware[$bCode]);
                
                for ($w = 0; $w < $windowsInRoom; $w++) {
                    $windowCount++;
                    $wNum = $bCode . '-F-' . str_pad((string)$windowCount, 4, '0', STR_PAD_LEFT);
                    $recId = 'ref-' . $bCode . '-' . str_pad((string)$windowCount, 5, '0', STR_PAD_LEFT);
                    $status = $pick($statuses);
                    $typ = $pick($typen);
                    $breite = $randInt(800, 1800);
                    $hoehe = $randInt(1000, 2200);
                    
                    $formData = json_encode([
                        'fenstertyp' => $typ,
                        'hersteller' => $hw[0],
                        'beschlagsystem' => $hw[1],
                        'baujahr' => $hw[2],
                        'breite_mm' => $breite,
                        'hoehe_mm' => $hoehe,
                        'glastyp' => $pick($glastypen),
                        'lage' => $pick($lagen),
                    ], JSON_UNESCAPED_UNICODE);
                    
                    $stmtW->execute([
                        $roomId, $recId, $wNum,
                        $buildings[array_search($bCode, array_column($buildings, 1))][0], // building name
                        $floorName, $rName, $rNumber, $status, $formData
                    ]);
                    $winId = (int)$pdo->lastInsertId();
                    
                    // Sashes (1-2 per window)
                    $sashes = ($typ === 'Fest') ? 1 : ($breite > 1400 ? 2 : 1);
                    for ($s = 1; $s <= $sashes; $s++) {
                        $sashCount++;
                        $sashStatus = $status;
                        $stmtS->execute([$winId, $s, "Flügel $s", $typ === 'Fest' ? 'Festverglasung' : $typ, $sashStatus]);
                    }
                }
            }
        }
    }
    
    // Add extra windows to reach 810+ (fill up TZ and VGS with additional rooms)
    $extraRooms = [
        ['TZ', 2, 'Werkstatt Zusatz 1', 4],['TZ', 2, 'Werkstatt Zusatz 2', 4],
        ['TZ', 3, 'Büro 304', 2],['TZ', 3, 'Büro 305', 2],['TZ', 3, 'Büro 306', 2],
        ['VGS', 1, 'Büro 106', 2],['VGS', 1, 'Büro 107', 2],['VGS', 1, 'Büro 108', 2],
        ['VGS', 2, 'Büro 207', 2],['VGS', 2, 'Büro 208', 2],['VGS', 2, 'Büro 209', 2],
        ['VGN', 0, 'Büro 7', 2],['VGN', 0, 'Büro 8', 2],['VGN', 2, 'Büro 306', 2],['VGN', 2, 'Büro 307', 2],
        ['KAS', 0, 'Speisesaal 2', 6],['KAS', 0, 'Speisesaal 3', 6],
        ['TZ', 0, 'Werkstatt Glas', 4],['TZ', 1, 'Labor 2', 3],['TZ', 1, 'Büro 103', 2],
        ['VGN', 1, 'Büro 211', 2],['VGN', 1, 'Büro 212', 2],['VGN', 1, 'Büro 213', 2],
        ['VGS', 0, 'Großraum 3', 5],['VGS', 0, 'Poststelle', 2],
        ['TZ', 4, 'Lüftung 2', 2],['TZ', 4, 'Technik 2', 1],
        ['KON', 0, 'Konferenz 3', 4],['KON', 1, 'Seminar 4', 3],
        ['PW', 0, 'Besucherraum', 2],['NGW', 0, 'Büro 2', 2],['NGW', 1, 'Büro 103', 2],
    ];
    
    foreach ($extraRooms as $er) {
        $bCode = $er[0]; $level = $er[1]; $rName = $er[2]; $cnt = $er[3];
        if (!isset($floorIds[$bCode][$level])) continue;
        $fId = $floorIds[$bCode][$level];
        $roomNum++;
        $rNumber = $bCode . '-X' . str_pad((string)$roomNum, 3, '0', STR_PAD_LEFT);
        
        $stmtR->execute([$fId, $rName, $rNumber, 99]);
        $roomId = (int)$pdo->lastInsertId();
        $hw = $pick($bHardware[$bCode]);
        $floorName = '';
        foreach ($floorDefs[$bCode] as $fd) { if ($fd[1] === $level) $floorName = $fd[0]; }
        
        for ($w = 0; $w < $cnt; $w++) {
            $windowCount++;
            $wNum = $bCode . '-F-' . str_pad((string)$windowCount, 4, '0', STR_PAD_LEFT);
            $recId = 'ref-' . $bCode . '-' . str_pad((string)$windowCount, 5, '0', STR_PAD_LEFT);
            $status = $pick($statuses);
            $typ = $pick($typen);
            $formData = json_encode([
                'fenstertyp' => $typ, 'hersteller' => $hw[0], 'beschlagsystem' => $hw[1],
                'baujahr' => $hw[2], 'breite_mm' => $randInt(800,1600), 'hoehe_mm' => $randInt(1000,2000),
                'glastyp' => $pick($glastypen), 'lage' => $pick($lagen),
            ], JSON_UNESCAPED_UNICODE);
            
            $stmtW->execute([$roomId, $recId, $wNum,
                $buildings[array_search($bCode, array_column($buildings, 1))][0],
                $floorName, $rName, $rNumber, $status, $formData]);
            $winId = (int)$pdo->lastInsertId();
            
            $sashes = ($typ === 'Fest') ? 1 : ($randInt(800,1600) > 1400 ? 2 : 1);
            for ($s = 1; $s <= $sashes; $s++) {
                $sashCount++;
                $stmtS->execute([$winId, $s, "Flügel $s", $typ === 'Fest' ? 'Festverglasung' : $typ, $status]);
            }
        }
    }
    
    return [
        'ok' => true,
        'message' => "Referenzprojekt erfolgreich angelegt.",
        'stats' => [
            'project_id' => 2,
            'buildings' => count($buildings),
            'floors' => array_sum(array_map('count', $floorDefs)),
            'windows' => $windowCount,
            'sashes' => $sashCount,
        ]
    ];
}
