<?php
declare(strict_types=1);

// Auch Fehler vor dem eigentlichen Jobstart immer als auswertbare JSON-Antwort
// liefern. Das Portal darf bei einem Serverfehler nicht nur eine leere 500-Seite
// erhalten; die Fehler-ID wird zugleich im Serverprotokoll hinterlegt.
$gfFatalHandled = false;
register_shutdown_function(static function () use (&$gfFatalHandled): void {
    if ($gfFatalHandled) return;
    $last = error_get_last();
    if (!is_array($last) || !in_array((int)($last['type'] ?? 0), [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) return;
    $gfFatalHandled = true;
    $errorId = bin2hex(random_bytes(4));
    $message = trim((string)($last['message'] ?? 'Unbekannter PHP-Fehler'));
    error_log('[gf-ai-fatal '.$errorId.'] '.$message);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['error'=>'Entwurf konnte nicht gestartet werden (Fehler-ID '.$errorId.'): '.$message], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});

$core = __DIR__ . '/gf-ai-generate-core.php';
$source = @file_get_contents($core);
if (!is_string($source) || $source === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'KI-Kernmodul konnte nicht geladen werden.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$source = preg_replace('/^<\?php\s*/u', '', $source, 1) ?? $source;

// KI-Antworten koennen optionale JSON-Felder als null oder als strukturierte
// Werte liefern. Regulare Pruefungen sollen daran nicht mit einem PHP-TypeError
// abbrechen, sondern den normalisierten Text fachlich bewerten.
function gfSafePregMatch(string $pattern, mixed $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int|false
{
    if (is_string($subject)) {
        $text = $subject;
    } elseif ($subject === null || is_scalar($subject)) {
        $text = (string)$subject;
    } else {
        $encoded = json_encode($subject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $text = is_string($encoded) ? $encoded : '';
    }
    return preg_match($pattern, $text, $matches, $flags, $offset);
}

function gfLaunchReportWorker(): bool
{
    $worker = __DIR__ . '/gf-ai-worker-cli.php';
    $setsid = '/usr/bin/setsid';
    $php = '/usr/bin/php';
    if (!is_file($worker) || !is_executable($php) || !is_executable($setsid) || !function_exists('exec')) return false;
    $command = escapeshellarg($setsid) . ' -f ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' >/dev/null 2>&1 </dev/null';
    $output = [];
    $status = 1;
    exec($command, $output, $status);
    return $status === 0;
}

// Ältere bzw. zwischengespeicherte Portaloberflächen haben die sichtbare
// Einzelauswahl vereinzelt nicht als order.outputs übertragen. Für diesen
// Fall den eindeutig im Arbeitsauftrag bezeichneten Berichtstyp übernehmen,
// statt die sichtbare Auswahl fälschlich mit „Keine Dokumente ausgewählt“
// abzulehnen.
$outputNeedle = <<<'PHP_CODE'
$folderId=trim((string)($body['folder_id']??''));$order=is_array($body['order']??null)?$body['order']:[];$outputs=is_array($order['outputs']??null)?$order['outputs']:[];requireCaseFolderAccess($folderId,$user);if(!$outputs)apiError(400,'Keine Dokumente ausgewählt.');
PHP_CODE;
$outputReplacement = <<<'PHP_CODE'
$folderId=trim((string)($body['folder_id']??''));$order=is_array($body['order']??null)?$body['order']:[];$outputs=is_array($order['outputs']??null)?array_values(array_filter(array_map('strval',$order['outputs']))):[];if(!$outputs){$selectionText=mb_strtolower(trim((string)($order['instructions']??'')),'UTF-8');$caseType=mb_strtolower(trim((string)($order['case_type']??'')),'UTF-8');$selectionMap=['rekon_schaden'=>'/\brekon(?:[- ]?schaden(?:bericht)?)?\b/u','erstbericht_sv_gf'=>'/(?:erstbericht\s*sv[- ]?gf|sv[- ]?gf[- ]?erstbericht|engel[- ]?erstbericht|erstbericht[^\n]{0,500}\bengel\b|\bengel\b[^\n]{0,500}erstbericht)/u','zwischenbericht'=>'/\bzwischenbericht\b/u','nachtrag_stellungnahme'=>'/\b(?:nachtrag|stellungnahme)\b/u','schlussbericht'=>'/\bschlussbericht\b/u','schlusserklaerung'=>'/\bschlusserkl[aä]rung\b/u','zahlungsbefuerwortung'=>'/\bzahlungsbef[uü]rwortung\b/u','query_form'=>'/\b(?:r[uü]ckfrageformular|r[uü]ckfrage)\b/u','kalkulation'=>'/\bkalkulation\b/u','erstbericht'=>'/\berstbericht\b/u'];if(preg_match('/\bsv[- ]?gf\b/u',$caseType)===1&&preg_match('/\berstbericht\b/u',$selectionText)===1){$outputs=['erstbericht_sv_gf'];}else{foreach($selectionMap as$selectionKey=>$selectionPattern){if(preg_match($selectionPattern,$selectionText)===1){$outputs=[$selectionKey];break;}}}}$order['outputs']=$outputs;requireCaseFolderAccess($folderId,$user);if(!$outputs)apiError(400,'Keine Dokumente ausgewählt.');
PHP_CODE;
$source = str_replace($outputNeedle, $outputReplacement, $source, $outputRepairCount);
if ($outputRepairCount !== 1) {
    throw new RuntimeException('Dokumentauswahl konnte nicht sicher initialisiert werden.');
}
$source = str_replace(
    '$order[\'outputs\']=$outputs;requireCaseFolderAccess($folderId,$user);',
    '$explicitOutput=trim((string)($body[\'selected_output\']??($_GET[\'selected_output\']??\'\')));if($explicitOutput!==\'\')$outputs=[$explicitOutput];$order[\'outputs\']=$outputs;requireCaseFolderAccess($folderId,$user);',
    $source,
    $explicitOutputCount
);
if ($explicitOutputCount !== 1) {
    throw new RuntimeException('Explizite Dokumentauswahl konnte nicht sicher angebunden werden.');
}

// Umfangreiche Schadenberichte benötigen neben dem sichtbaren JSON auch Raum
// für interne Reasoning-Tokens. Der Grenzwert bleibt per Umgebung anpassbar.
$source = str_replace(
    "'max_output_tokens'=>\$maxOutputTokens??9000",
    "'max_output_tokens'=>\$maxOutputTokens??(int)env('OPENAI_MAX_OUTPUT_TOKENS','20000')",
    $source,
    $outputTokenCount
);
if ($outputTokenCount !== 1) {
    throw new RuntimeException('Ausgabereserve für KI-Berichte konnte nicht sicher gesetzt werden.');
}

// Rekon benötigt weder Schadenfotos noch die umfangreiche allgemeine
// Regelwerksammlung. Beides würde Laufzeit und Tokenkosten unnötig erhöhen.
$rekonSourceNeedle = <<<'PHP_CODE'
$caseFiles=gfCaseFiles($folderId);if(!$caseFiles)throw new RuntimeException('Im aktiven Fall wurden keine auswertbaren Unterlagen gefunden.');$knowledgeFiles=gfKnowledgeFiles();if(!$knowledgeFiles)throw new RuntimeException('Keine Engel/QS-/GF-Regelwerke in der Google-Drive-Wissensbasis gefunden.');gfJobUpdate($jobId,'running',8,'Fallunterlagen werden für die KI vorbereitet.');
PHP_CODE;
$rekonSourceReplacement = <<<'PHP_CODE'
$rekonOnly=count($outputs)===1&&in_array('rekon_schaden',$outputs,true);$caseFiles=gfCaseFiles($folderId);if($rekonOnly)$caseFiles=array_values(array_filter($caseFiles,static fn($file)=>!str_starts_with(mb_strtolower((string)($file['mimeType']??''),'UTF-8'),'image/')));if(!$caseFiles)throw new RuntimeException($rekonOnly?'Für den Rekon-Bericht wurden keine auswertbaren Unterlagen ohne Bilder gefunden.':'Im aktiven Fall wurden keine auswertbaren Unterlagen gefunden.');$knowledgeFiles=$rekonOnly?[]:gfKnowledgeFiles();if(!$rekonOnly&&!$knowledgeFiles)throw new RuntimeException('Keine Engel/QS-/GF-Regelwerke in der Google-Drive-Wissensbasis gefunden.');gfJobUpdate($jobId,'running',8,$rekonOnly?'Rekon-Unterlagen werden ohne Bilder vorbereitet.':'Fallunterlagen werden für die KI vorbereitet.');
PHP_CODE;
$source = str_replace($rekonSourceNeedle, $rekonSourceReplacement, $source, $rekonSourceCount);
if ($rekonSourceCount !== 1) throw new RuntimeException('Kostenarme Rekon-Quellenauswahl konnte nicht angebunden werden.');
$source = str_replace("if(!\$ruleRefs)throw new RuntimeException('Regelwerke konnten der KI nicht bereitgestellt werden.');", "if(!\$rekonOnly&&!\$ruleRefs)throw new RuntimeException('Regelwerke konnten der KI nicht bereitgestellt werden.');", $source, $rekonRulesCount);
if ($rekonRulesCount !== 1) throw new RuntimeException('Rekon-Regelwerksausnahme konnte nicht angebunden werden.');

// Start und Langläufer trennen: Der Browser erhält zuerst sicher die Job-ID.
// Die eigentliche Verarbeitung wird anschließend über action=run angestoßen,
// während die Statusabfragen unabhängig weiterlaufen können.
$dispatcherNeedle = <<<'PHP_CODE'
$body=requestBody();$action=(string)($body['action']??'start');gfJobsTable();
PHP_CODE;
$dispatcherReplacement = <<<'PHP_CODE'
$body=requestBody();$action=(string)($body['action']??'start');gfJobsTable();if(in_array($action,['run','resume'],true)){$id=(int)($body['job_id']??0);if($id<=0)apiError(400,'job_id fehlt.');$stmt=db()->prepare('SELECT payload_json,status,started_at,heartbeat_at FROM gf_ai_jobs WHERE id=:id AND created_by=:u LIMIT 1');$stmt->execute([':id'=>$id,':u'=>(string)($user['email']??'')]);$runRow=$stmt->fetch(PDO::FETCH_ASSOC);if(!$runRow)apiError(404,'KI-Auftrag nicht gefunden.');$runStatus=(string)($runRow['status']??'');$canResume=$action==='resume'&&gfJobRecoverable($runRow);if($runStatus==='queued'||$canResume){$condition=$runStatus==='queued'?"status='queued'":"status IN ('running','dispatching') AND (heartbeat_at IS NULL OR heartbeat_at<DATE_SUB(NOW(),INTERVAL ".max(900,(int)env('GF_AI_STALE_SECONDS','900'))." SECOND))";$claim=db()->prepare("UPDATE gf_ai_jobs SET status='dispatching',message='Unterbrochene Verarbeitung wird fortgesetzt.',started_at=COALESCE(started_at,NOW()),heartbeat_at=NOW(),attempt_count=attempt_count+1 WHERE id=:id AND created_by=:u AND ".$condition);$claim->execute([':id'=>$id,':u'=>(string)($user['email']??'')]);if($claim->rowCount()===1){$runPayload=json_decode((string)($runRow['payload_json']??''),true);if(!is_array($runPayload)){gfJobUpdate($id,'failed',100,'Verarbeitung fehlgeschlagen.',null,'KI-Auftrag ist unvollständig.');apiError(500,'KI-Auftrag ist unvollständig.');}$runResponse=json_encode(['ok'=>true,'accepted'=>true,'job_id'=>$id,'status'=>'dispatching'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);http_response_code(202);header('Content-Type: application/json; charset=utf-8');header('Content-Length: '.strlen($runResponse));header('Connection: close');echo $runResponse;if(function_exists('fastcgi_finish_request')){fastcgi_finish_request();}else{while(ob_get_level()>0){@ob_end_flush();}@flush();}gfRunJob($id,$runPayload);exit;}}if($action==='resume')apiError(409,'Der Auftrag ist nicht unterbrochen oder wurde bereits fortgesetzt.');apiJson(['ok'=>true,'job_id'=>$id,'status'=>$runStatus]);}
PHP_CODE;
$source = str_replace($dispatcherNeedle, $dispatcherReplacement, $source, $dispatcherCount);
if ($dispatcherCount !== 1) {
    throw new RuntimeException('KI-Hintergrundstarter konnte nicht sicher initialisiert werden.');
}
$source = str_replace(
    "echo \$runResponse;if(function_exists('fastcgi_finish_request')){fastcgi_finish_request();}else{while(ob_get_level()>0){@ob_end_flush();}@flush();}gfRunJob(\$id,\$runPayload);exit;",
    "if(!gfLaunchReportWorker()){gfJobUpdate(\$id,'failed',100,'Verarbeitung fehlgeschlagen.',null,'Server-Worker konnte nicht gestartet werden.');apiError(500,'Server-Worker konnte nicht gestartet werden.');}echo \$runResponse;exit;",
    $source,
    $workerDispatchCount
);
if ($workerDispatchCount !== 1) {
    throw new RuntimeException('Server-Worker konnte nicht sicher an den Dispatcher angebunden werden.');
}
$inlineRunNeedle = <<<'PHP_CODE'
echo $response;if(function_exists('fastcgi_finish_request')){fastcgi_finish_request();}else{while(ob_get_level()>0){@ob_end_flush();}@flush();}gfRunJob($jobId,$payload);exit;
PHP_CODE;
$source = str_replace($inlineRunNeedle, 'echo $response;exit;', $source, $inlineRunCount);
if ($inlineRunCount !== 1) {
    throw new RuntimeException('KI-Hintergrundlauf konnte nicht sicher angebunden werden.');
}


// Fertige Falldokumente ausschließlich über das angemeldete Portal ausliefern.
// Direkte Drive-Links würden bei den Sachverständigen ein zusätzliches
// privates Google-Login verlangen und die Portalberechtigung umgehen.
$source = str_replace(
    "'webViewLink'=>\$file['webViewLink']??null,'webContentLink'=>\$file['webContentLink']??null",
    "'webViewLink'=>'/intern/api/case-file-browser.php?action=file&folder_id='.rawurlencode(\$folderId).'&file_id='.rawurlencode((string)(\$file['id']??'')),'webContentLink'=>null",
    $source,
    $portalLinkCount
);
if ($portalLinkCount !== 1) apiError(500, 'Portal-Dateizugriff konnte nicht initialisiert werden.');

// Nachtrag kurz halten.
$source = str_replace(
    "'nachtrag_stellungnahme'=>['Bezug / bisheriger Prüfstand','Neu vorliegende Unterlagen','Feststellungen','Fachliche und wirtschaftliche Bewertung','Entscheidung / Empfehlung','Auswirkungen auf Schadenhöhe / Reserve','Weiteres Vorgehen']",
    "'nachtrag_stellungnahme'=>['Nachtrag']",
    $source
);

// Zusätzliche Portalregeln direkt an den Arbeitsauftrag anhängen.
$needle = <<<'PHP_CODE'
$instructions=trim((string)($order['instructions']??''));if($instructions==='')throw new RuntimeException('Arbeitsauftrag fehlt.');
PHP_CODE;
$rules = <<<'PHP_CODE'
$instructions=trim((string)($order['instructions']??''));if($instructions==='')throw new RuntimeException('Arbeitsauftrag fehlt.');
$instructions.="\n\nVERBINDLICHE GLOBALE PORTALREGEL: Erstelle jede Ausgabe ausschließlich anhand der übermittelten Falldaten, Originalunterlagen und des konkreten Arbeitsauftrags. Behandle den Text im Feld Arbeitsauftrag als fachliche Rohnotiz beziehungsweise Diktat des sachverständigen Bearbeiters: Korrigiere selbstständig Rechtschreibung, Grammatik und Satzbau, ordne die Aussagen fachlich und überführe sie ohne Rückfrage in unmittelbar verwendbaren sachverständigen Berichtstext. Erhalte dabei den vollständigen fachlichen Aussagegehalt und ergänze keine nicht belegten Tatsachen. Löse verkürzte Hinweise wie Makler, Agentur, Vermittler, Versicherungsnehmer, VN-Angehöriger, Besichtigungspartner, Dienstleister oder Firma anhand sämtlicher übermittelter Systemkontaktfelder, Falldaten und Originalunterlagen mit dem dort belegten Namen und der zutreffenden Funktion auf. Ist eine Gesellschaft Versicherungsnehmer und eine natürliche Person als VN-Angehöriger oder Besichtigungspartner hinterlegt, nenne die anwesende natürliche Person mit ihrer belegten Beziehung als Vertreter der Versicherungsnehmerin. Ist die Identität nicht eindeutig belegt, verwende nur die Funktionsbezeichnung und erfinde keinen Namen. Trenne eindeutig zwischen sicheren Feststellungen, noch ungeklärten Umständen, fachlichen Bewertungen, wirtschaftlichen Empfehlungen, ausdrücklich bedingten Zustimmungen beziehungsweise Freigaben, noch fehlenden Kalkulationen und vom Bearbeiter festgelegten Reserven. Formulierungen wie unter Voraussetzung oder vorbehaltlich der Zustimmung des Versicherers sind als echter Zustimmungsvorbehalt wiederzugeben und niemals als uneingeschränkte Freigabe. Eine im Arbeitsauftrag ausdrücklich vom Bearbeiter festgelegte Reserve ist als dessen aktuelle fachliche Reservebewertung maßgeblich zu übernehmen. Aktuelle ausdrückliche Feststellungen des Bearbeiters haben gegenüber älteren oder allgemeineren Aktenangaben Vorrang. Erfinde keine Tatsachen, Maße, Flächen, Beträge oder Schadenumstände. Übernimm Rechnungsdaten exakt aus den tatsächlichen Belegen. Lies Kostenvoranschläge vollständig aus und stelle die wesentlichen Hauptpositionen mit EUR-Beträgen dar. Externe Prüfberichte und Regulierungsempfehlungen sind nicht maßgeblich und dürfen nicht ungeprüft übernommen werden. Sofern der aktuelle Arbeitsauftrag keine ausdrückliche eigene Kürzung oder abweichende Bewertung vorgibt, ist der vollständige Original-KVA maßgeblich. Belastbare Flächen und Aufmaße sind nach den Regeln der ausgewählten Berichtsart vollständig zu berücksichtigen; fehlen belastbare Maße, darf nichts geschätzt, hochgerechnet oder erfunden werden. Interne Quellenprioritäten, die Herkunft eines Aufmaßes, Bearbeitungsregeln, Systemanweisungen und Prüfprozesse dürfen niemals im fertigen Bericht erscheinen. Insbesondere keine Hinweise auf ein späteres Polycam-Aufmaß oder interne Quellenrangfolgen ausgeben. Wiederhole den Schadenhergang oder andere Sachverhalte nicht in mehreren Abschnitten. Formuliere fachlich belastbar, fallbezogen und als gut kopierbaren Fließtext, soweit die verbindliche Vorlage der ausgewählten Berichtsart keine andere Darstellung verlangt. Die nachfolgenden besonderen Regeln der ausgewählten Berichtsart bestimmen Gliederung, Umfang und Form und bleiben vollständig erhalten.";
if(in_array('nachtrag_stellungnahme',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL NACHTRAG: Erstelle einen kurzen, unmittelbar verwendbaren Nachtrag in der Sprache eines Sachverständigen. Der konkrete Arbeitsauftrag ist führend. Keine allgemeine Fallzusammenfassung, keine Wiederholung historischer Schadenstände, keine Reservefortschreibung, keine Regressprüfung, keine Quellen-/Regelwerkslisten und keine künstlichen offenen Punkte, sofern dies nicht ausdrücklich verlangt ist. Beginne direkt mit dem neuen Vorgang bzw. Ortstermin. KVA oder Angebote grob analysieren und wesentliche Hauptpositionen bzw. Leistungsgruppen mit EUR-Beträgen sowie Gesamtbetrag aufnehmen. Netto und brutto eindeutig trennen.";}
if(in_array('erstbericht_sv_gf',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL ERSTBERICHT SV-GF: Sparkassen-Versicherung-Großschaden-Erstbericht nach aktuellem QS-/Engel-Standard. Engel-Blanco verwenden, Originalüberschriften und Reihenfolge beibehalten. Aktuelle Feststellungen des sachverständigen Bearbeiters haben Vorrang vor älteren Angaben. Schadenursächliches Bauteil und versicherte Folgeschäden strikt trennen. KVA/Angebote selbstständig sachverständig prüfen und wesentliche Leistungsgruppen mit EUR-Beträgen und Gesamtbetrag aufnehmen. Externe Prüfberichte, insbesondere von PropertyExpert oder vergleichbaren Prüfdienstleistern, sind nicht maßgeblich und deren Kürzungen oder Regulierungsempfehlungen niemals automatisch übernehmen. Sofern der aktuelle Arbeitsauftrag keine ausdrückliche abweichende Bewertung oder Kürzung vorgibt, gilt der vollständige Original-KVA als Bewertungs- und Freigabebetrag. Diese Regel gilt auch bei der Sparkassen Versicherung. Belegidentität ausschließlich aus Originalbelegen bestimmen. Rechnungen einzeln mit Aussteller, Rechnungsnummer, Datum und Betrag aufnehmen, soweit geprüft oder belegt. Reserve ausschließlich im Abschnitt Reserve EUR und dort nur als Zahl bzw. spartengetrennte Zahlen. Regress belastbar behandeln. Nichts erfinden.";}
if(in_array('rekon_schaden',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL REKON-SCHADEN: Erstelle den Bericht als Rekon-Formular ohne Bilder, Fotoabschnitt, Dokumentseiten oder Quellenanhang. Verwende exakt die vorgegebenen zehn Abschnittsüberschriften und innerhalb jedes Abschnitts exakt die folgende Feldreihenfolge. Jede Feldzeile hat zwingend das Format Feldbezeichnung: Wert. Unbekannte Werte bleiben nach dem Doppelpunkt leer; keine Platzhalter und nichts erfinden. Bei Auswahlfeldern ausschließlich belegte ausgewählte Werte aus den zugelassenen Rekon-Auswahlmöglichkeiten nennen; mehrere Werte mit Semikolon trennen. Längere Freitexte fachlich ausformulieren und in derselben Feldzeile beziehungsweise in unmittelbar folgenden Fortsetzungszeilen ausgeben. Reihenfolge: Basisdaten Erfassung — Datum der Besichtigung; Besichtigung bis; Teilnehmer Ortstermin; Bemerkungen zu Teilnehmer; Objektart; Bauartklasse; Baujahr; Wohnfläche in m²; Denkmalschutz; Zustand; Eigentums- / Besitzverhältnisse; Nutzungsart. Schadenhergang, Plausibilität und Ursache — Schadentag; Schadenschilderung durch; Schadenschilderung der o. g. Beteiligten; Schaden bemerkt durch; Feststellung des Außenregulierers vor Ort; Versicherungsort bewohnt; Welche Personen waren zum Schadenzeitpunkt zu Hause; Polizei/Feuerwehr informiert; Angaben zur Plausibilität; Ort der Schadenursache; Schadenursache; Detailbeschreibung; Schadenschilderung. Bestimmungswidriger Leitungswasseraustritt — LW-Folgeschäden; Wasseraustritt erfolgte aus; Weitere Angaben zum Wasseraustritt (Etage, Name, Räume); Wohnräume/Räume noch bewohnbar/nutzbar; Getroffene Not-/Sofortmaßnahmen. Ersatzpflicht — Ersatzpflicht; Obliegenheitsverletzungen; Sicherheitsvorschriften verletzt. Schadenhöhe und Umfang — Schadenhöhe; Reserveempfehlung; Vorsteuerabzugsberechtigung. Prüfung Versicherungssumme (überschlägig) — Versicherungssumme ausreichend. Regress — Regressmöglichkeiten; Wurde der schadenursächliche Gegenstand gesichert. Mehrfachversicherung — Mehrfachversicherung möglich; Keine Mehrfachversicherung möglich weil. Bankverbindung / Zahlweise — Zahlweise. Weitere Vorgehensweise — Wir empfehlen. Die Chronologie ist führend: zuerst Ortstermin und Objektdaten, danach Hergang/Plausibilität/Ursache, Leitungswasseraustritt, Ersatzpflicht, Schadenhöhe, Versicherungssumme, Regress, Mehrfachversicherung, Zahlweise und weitere Vorgehensweise. Rekon-Auswahlwerte niemals umformulieren, sofern ein belegter Originalwert vorliegt. Die Word-Ausgabe darf keine Bilder enthalten.";}
if(in_array('erstbericht',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL ALLGEMEINER ERSTBERICHT: Erstelle einen unmittelbar versendbaren sachverständigen Erstbericht aus vollständiger Fallakte und Arbeitsauftrag. Schreibe unter den vorgegebenen fachlichen Überschriften ausschließlich ausformulierte, gut kopierbare Fließtextabsätze; kein Formular, keine Stichpunktmaske und keine Tabellen. Der aktuelle Ortstermin und ausdrückliche Feststellungen des Bearbeiters sind maßgeblicher aktueller Sachstand. Der normale Schadenbericht ist vollständig abzuarbeiten: Risiko; Schadenhergang; Schadenursache; Schadenumfang; Ersatzpflicht; Regress; Obliegenheitsverletzungen; Doppelversicherung; Polizei bei ED/Feuer; Handlungsempfehlungen; Kalkulation. DIE DREI SCHADENABSCHNITTE IMMER EIGENSTÄNDIG UND EINGEHEND BEARBEITEN: Schadenhergang stellt ausschließlich den zeitlichen und tatsächlichen Ablauf dar, insbesondere Schadenzeitpunkt beziehungsweise erstmalige Wahrnehmung, Schadenmeldung, Entwicklung, Sofortmaßnahmen, Ortstermine und maßgebliche Abstimmungen. Bei jedem im Arbeitsauftrag genannten Ortstermin sind sämtliche ausdrücklich genannten Teilnehmer aufzunehmen. Namen und Funktionen aus Falldaten, Systemfeldern und Originalunterlagen auflösen; einen ausdrücklich genannten Versicherungsnehmer, Makler, Vermittler, Dienstleister oder sonstigen Beteiligten niemals weglassen. Ist bei einer ausdrücklich genannten Funktion kein eindeutiger Personenname belegt, die Funktion ohne erfundenen Namen nennen. Schadenursache behandelt ausschließlich das schadenursächliche Bauteil, den technischen Schadenmechanismus, Untersuchungen und Nachweise, den Grad der Feststellungssicherheit sowie verbleibende technische Unklarheiten; sichere Feststellungen und bloße Möglichkeiten ausdrücklich trennen. Schadenumfang beschreibt ausschließlich alle festgestellten und technisch zu erwartenden Auswirkungen, betroffenen Räume, Bauteile und Konstruktionen, Feuchte- und Schadenbilder, notwendige Bauteilöffnungen, Trocknungs-, Rückbau- und Wiederherstellungsbereiche sowie vorhandene belastbare Maße und Aufmaße. Vor Fertigstellung alle Fallunterlagen gezielt nach Einheiten und Mengenangaben wie m², qm, m, lfm, Länge, Breite, Höhe, Umfang, Bodenfläche, Wandfläche und Deckenfläche durchsuchen und sämtliche für den Schadenumfang relevanten belastbaren Werte aufnehmen. Ein vorhandenes Raum- oder Schadenaufmaß darf nicht deshalb entfallen, weil zusätzlich Leitungslängen oder KVA-Mengen vorhanden sind. Alle aus Akte und Arbeitsauftrag verfügbaren relevanten Einzelangaben auswerten; keinen dieser drei Abschnitte mit einer pauschalen Kurzformel abhandeln. Fehlen zu einem Unterpunkt belastbare Angaben, die Lücke knapp benennen und nichts erfinden. JEDEN SACHVERHALT NUR EINMAL DARSTELLEN: Risiko enthält ausschließlich Vertrag, Versicherungsobjekt und Risikomerkmale; Ersatzpflicht enthält nur Deckungsbewertung und Ergebnis ohne erneute Schilderung; Regress, Obliegenheiten, Doppelversicherung und Polizei enthalten nur das jeweilige Prüfergebnis; Handlungsempfehlungen enthalten nur künftige Maßnahmen; Kalkulation enthält ausschließlich Belegprüfung, Beträge, Freigabe und Reserve. Keine Wiederholung des Schadenhergangs, der Ursache, der betroffenen Bereiche oder der Maßnahmen in mehreren Kapiteln. Nicht einschlägige Punkte ausdrücklich mit Nein beziehungsweise nicht einschlägig und kurzer Begründung abschließen, nicht kommentarlos leer lassen. Schadenursache und Folgeschaden sauber trennen. KVA/Angebote auslesen, selbstständig sachverständig prüfen und Hauptleistungsgruppen mit EUR-Beträgen sowie Netto-, Umsatzsteuer- und Bruttosumme aufnehmen. Externe Prüfberichte, insbesondere von PropertyExpert oder vergleichbaren Prüfdienstleistern, sind nicht maßgeblich und deren Kürzungen oder Regulierungsempfehlungen niemals automatisch übernehmen. Sofern der aktuelle Arbeitsauftrag keine ausdrückliche abweichende Bewertung oder Kürzung vorgibt, gilt der vollständige Original-KVA als Bewertungs- und Freigabebetrag. Belegidentität ausschließlich aus Originalbelegen bestimmen. Rechnungen einzeln mit Aussteller, Rechnungsnummer, Datum und Betrag aufnehmen, soweit geprüft oder belegt. AUFMASS IM BERICHT QUELLENNEUTRAL FORMULIEREN: Maße und Flächen als sachverständige Feststellung mit Rechenansatz ausgeben. Im Aufmaßabsatz niemals Firmen-, Dokument-, Angebots-, KVA-, Gutachten-, Prüfbericht- oder Produktnamen und niemals Formulierungen wie laut, gemäß, anhand, aus dem Angebot, im Angebot ausgewiesen, belegt oder übernommen verwenden. Die interne Herkunft und Quellenpriorität darf nicht erkennbar sein.  SACHVERSTÄNDIGE HERLEITUNG STATT AKTENREFERAT: Der Bericht darf die Akte nicht nur zusammenfassen oder Positionen aufzählen, sondern muss aus den belegten Feststellungen eine fachlich nachvollziehbare Bewertung und eine eindeutige, entscheidungsreife Empfehlung ableiten. Den Zusammenhang zwischen Ursache, Leitungsweg, notwendigen Bauteilöffnungen, Trocknungsfähigkeit, Rückbau, Wiederherstellung und wirtschaftlicher Ausführungsvariante konkret erläutern, ohne denselben Sachverhalt in mehreren Kapiteln zu wiederholen. Bei einer Bypass- oder Alternativlösung den technischen Zweck, den gegenüber einer vollständigen Freilegung geringeren Eingriff und die wirtschaftliche Begründung fallbezogen darstellen; einen Zustimmungsvorbehalt des Versicherers vollständig erhalten. Raumgrundflächen, Bauteilflächen und tatsächlich geschädigte Flächen strikt unterscheiden. Eine Raumgröße darf nur als Raum- beziehungsweise Aufmaßangabe bezeichnet und nicht ohne Beleg zur vollständig betroffenen Schadenfläche erklärt werden. Nur gleichartige und räumlich überschneidungsfreie Größen addieren; Boden-, Wand-, Deckenflächen, Raumumfänge und Leitungslängen niemals zu einer Gesamtfläche vermischen. Bei Gipskarton und anderen nur eingeschränkt trocknungsfähigen Bekleidungen konkret trennen, welche Bereiche technisch getrocknet werden können und welche wegen fehlender wirtschaftlicher Trocknungsfähigkeit geöffnet, ausgebaut oder erneuert werden müssen; keine widersprüchlichen Pauschalsätze. Geplante, bereits ausgeführte, angebotene, bezahlte und noch zu kalkulierende Leistungen sprachlich eindeutig unterscheiden. Vermeide unverbindliche Standardsätze wie der KVA ist abzugleichen, die Zahlung ist fortzuführen oder die weitere Vorgehensweise ist abzustimmen, wenn anhand der Akte bereits eine konkrete eigene Bewertung möglich ist. Im Abschnitt Kalkulation die fachliche Prüfung mit Ergebnis formulieren. Wenn keine eigene Kürzung oder abweichende Bewertung vorgegeben ist, den vollständigen Original-KVA mit Netto-, Umsatzsteuer- und Bruttobetrag als maßgeblichen Ansatz benennen; das ist keine erfundene Kürzung oder Fremdprüfung. Reserve und Selbstbehalt strikt trennen: Für die Reserve hat der aktuelle Regulierungsauftrag mit dem Feld Gesamtreserve aktuell beziehungsweise Erstreserve Vorrang vor sonstigen Metadaten und älteren Unterlagen. Selbstbehalt, Forderung, KVA-, Rechnungs- oder Entschädigungsbetrag niemals als Reserve interpretieren. Ist keine Reserve ausdrücklich belegt, keine Reserve ausgeben. Nichts erfinden.";}
if(in_array('schadenprotokoll',$outputs,true)){$instructions.="\n\nVERBINDLICHER STANDARD UND FORMATLOGIK SV-SCHADENPROTOKOLL: Ausschließlich das offizielle Originalformular verwenden und strukturell nicht verändern. Die komplette Typografie des Vordrucks unverändert erhalten. Originaltexte, Überschriften, Feldbezeichnungen, Belehrungstexte, Checkboxtexte und alle sonstigen Vordruckbestandteile dürfen weder auf Arial umgestellt noch in ihrer Schriftgröße verändert werden. Ausschließlich neu eingetragene fallbezogene Inhalte in Arial 10 pt schreiben. Vor dem Ausfüllen die vollständige Akte auswerten. Bekannte Angaben vollständig übernehmen, unbekannte Angaben offen lassen und niemals erfinden. Formularkopf soweit bekannt vollständig mit VN, Schaden-Nr., Versicherungs-/Vertrags-Nr., Telefon, Mobil, E-Mail, Schadenort, Schadentag, Meldetag, Gesprächspartner und Regulierer befüllen. Bei WEG-Schäden die WEG als VN führen. Schadenhergang chronologisch und technisch; Risiko, Eigentum, Zahlungsadresse, Sachverständigenverfahren, Freigaben mit Firma/Belegnummer/Datum/EUR, Regress, erforderliche Unterlagen, Vereinbarungen/Schadenminderung sowie Mietausfall/Unbewohnbarkeit ausschließlich nach Aktenlage ausfüllen. Das Protokoll muss widerspruchsfrei mit allen Berichten sein. Jeder bekannte Wert muss innerhalb des vorgesehenen Feldes stehen: Feldbezeichnung oben, neuer Eintrag unmittelbar darunter und oberhalb der unteren horizontalen Abschlusslinie, Abschlusslinie darunter. Niemals einen Wert unterhalb der Linie in die nachfolgende Zeile setzen. Dies gilt insbesondere für Schaden-, Versicherungs- und Telefonnummern, Schadenort, Schaden- und Meldetag, Gesprächspartner, Regress, Verursacher, Eigentum des Bauteils, Mieterdaten, Gesellschaft und Versicherungsscheinnummer sowie für sämtliche Freitextfelder und Boxen zu KVA, Rechnungen, Sonstigem, Bemerkungen und Vereinbarungen. Ausnahme: Den Regulierernamen Christian Wächter gemäß Originalformularlogik direkt unter der Feldbezeichnung Name des Schadenregulierers eintragen. Vor Ausgabe zwingend jede Seite visuell prüfen: Originalformular und dessen Typografie unverändert; nur neue Einträge Arial 10; alle Einträge im richtigen Feld und oberhalb der jeweiligen Abschlusslinie; kein Text abgeschnitten, überlagert, außerhalb einer Box oder unterhalb einer Formularlinie. Bei jedem Positions- oder Typografiefehler Ausgabe und Speicherung sperren.";}
$instructions.="\n\nVERBINDLICHE FORMULARREGEL: Für alle Dokumenttypen ist das jeweilige Original aus dem zentralen Vorlagenbestand SV-GF Fälle zu verwenden. Originale niemals überschreiben oder verändern; nur eine neu erzeugte Ausgabe im aktiven Fall speichern. Normale Eintragungen Arial 10 pt, bestehende Überschriften und Feldpositionen unverändert. Vor Ausgabe vollständige Akte prüfen, Tatsachen nicht erfinden und eine Endkontrolle auf Stammdaten, Ursache, Ersatzpflicht, Umfang, Freigaben, Zahlungen, Reserve, Regress, Bilder/Pläne, Widersprüche und Formularlayout durchführen.";
$instructions.="\n\nVERBINDLICHE REDAKTIONSREGEL: Der fertige Bericht spricht in sachverständiger Fachsprache über den Fall und niemals über seine Erstellung. Unzulässig sind Metasätze wie vom Bearbeiter festgelegt, vom Bearbeiter als angemessen angesehen, ist zu übernehmen, im Arbeitsauftrag genannt, im System geführt oder anhand der Unterlagen übernommen. Eine ausdrücklich festgelegte Reserve ist neutral und knapp als fachliches Ergebnis zu formulieren, zum Beispiel: Die Schadenreserve wird auf 10.500,00 EUR festgesetzt. Aktuelle konkrete Feststellungen und Beträge aus dem Arbeitsauftrag gehen veralteten Aktenwerten vor. Alte Nullansätze, grobe Schwellenhinweise wie Kosten über 5.000 EUR und überholte Schätzstände dürfen nicht in den Bericht gelangen, wenn ein aktueller konkreter KVA, Rechnungsbetrag oder Reserveansatz vorliegt. Offene Punkte, weitere Termine, Terminabgleiche, Kontaktaufnahmen, Prüfungen oder Freigaben dürfen nur genannt werden, wenn sie im aktuellen Arbeitsauftrag ausdrücklich noch verlangt werden oder sich aus einem eindeutig fortbestehenden sachlichen Erfordernis ergeben. Aus der bloßen Nennung einer Person oder eines früheren Schriftwechsels darf niemals eine künftige Aufgabe erfunden werden. Teilnehmer eines bereits durchgeführten Ortstermins sind ausschließlich als Teilnehmer dieses Termins darzustellen. Schreibe konkret, entscheidungsorientiert und ohne Fülltext, Aktenreferate oder belanglose Wiederholungen.";
$hasErstbericht=false;foreach($outputs as$outputKey){$outputKey=(string)$outputKey;if(strpos($outputKey,'erstbericht')!==false||in_array($outputKey,['schadenbericht','sv_erstbericht','erstbericht_sv'],true)){$hasErstbericht=true;break;}}
if($hasErstbericht){$instructions.="\n\nVERBINDLICHE QS-REGEL FLÄCHENBERECHNUNG FÜR ALLE ERSTBERICHTE: In jedem Erstbericht ist eine Flächen-/Aufmaßdarstellung aufzunehmen, sofern belastbare Maße oder Flächen vorhanden sind. Erstelle vor dem Schreiben intern ein vollständiges Mengeninventar aus allen aktiven Fallunterlagen und dem Arbeitsauftrag. Nimm darin jede ausdrücklich mit m², qm oder m2 bezeichnete Fläche sowie eindeutig bezeichnete Längen, Breiten, Höhen und Umfänge mit Einheit auf und gleiche dieses Inventar abschließend mit dem Bericht ab. Priorität 1: eigene Aufmaße des Sachverständigen, insbesondere Polycam. Priorität 2: ausdrücklich ausgewiesene Maße und Flächen aus technischen Unterlagen wie Leckortungsbericht, Trocknungsbericht, Handwerker-Erstbericht, KVA, Angebot oder Aufmaß. Werte als eigene sachverständige Feststellung ohne Herkunftshinweis übernehmen. Boden-, Wand- und Deckenflächen, Raumumfänge, Längen und sonstige relevante Aufmaßwerte nachvollziehbar darstellen. Bei belastbar angegebenen Längen/Breiten darf die Fläche rechnerisch ermittelt werden. HARTE ABGRENZUNG: Nackte Zahlen und Werte aus Feuchtemessungen beziehungsweise DIGITS, Prozent-, CM-, Temperatur-, Druck- oder sonstigen Gerätemessungen sind niemals Maße oder Flächen. Sie dürfen weder mit m² versehen, zur Fläche umgerechnet noch in eine Flächensumme aufgenommen werden. DIGITS und einzelne numerische Feuchtemesswerte dürfen im fertigen Bericht überhaupt nicht genannt oder erläutert werden; Feuchte ausschließlich qualitativ als erhöht, unauffällig oder durchfeuchtet beschreiben. Eine bloße Dezimalzahl wird nur dann als Aufmaß verwendet, wenn ihr unmittelbarer fachlicher Kontext sie eindeutig als Fläche oder Maß mit Einheit ausweist. Bei Widersprüchen gilt die höhere Quellenpriorität; keine Mittelwerte. Fehlen belastbare Maße, nichts schätzen oder erfinden. Diese Regel gilt für normalen Schaden-Erstbericht, SV-Erstbericht und SV-GF-Erstbericht nach Engel. WICHTIG: Die interne Quellenpriorität und insbesondere Hinweise auf Polycam oder spätere Aufmaße dürfen niemals im Berichtstext erscheinen.";}
PHP_CODE;
if (!str_contains($source, 'VERBINDLICHE PORTALREGEL ALLGEMEINER ERSTBERICHT')) {
    $source = str_replace($needle, $rules, $source, $countInstructions);
    if ($countInstructions !== 1) {
        throw new RuntimeException('Portalregeln konnten nicht sicher an den Arbeitsauftrag angebunden werden.');
    }
}

// Rekon verwendet ein festes dynamisches Formular. Die KI liefert nur die
// fallbezogenen Werte; Reihenfolge und Word-Layout werden deterministisch hier
// erzeugt. Dadurch können weder Felder verrutschen noch Foto-/Dokumentseiten
// versehentlich in die Ausgabe gelangen.
function gfRekonSchema(): array
{
    return [
        'Basisdaten Erfassung' => ['Datum der Besichtigung','Besichtigung bis','Teilnehmer Ortstermin','Bemerkungen zu Teilnehmer','Objektart','Bauartklasse','Baujahr','Wohnfläche in m²','Denkmalschutz','Zustand','Eigentums- / Besitzverhältnisse','Nutzungsart'],
        'Schadenhergang, Plausibilität und Ursache' => ['Schadentag','Schadenschilderung durch','Schadenschilderung der o. g. Beteiligten','Schaden bemerkt durch','Feststellung des Außenregulierers vor Ort','Versicherungsort bewohnt','Welche Personen waren zum Schadenzeitpunkt zu Hause','Polizei/Feuerwehr informiert','Angaben zur Plausibilität','Ort der Schadenursache','Schadenursache','Detailbeschreibung','Schadenschilderung'],
        'Bestimmungswidriger Leitungswasseraustritt' => ['LW-Folgeschäden','Wasseraustritt erfolgte aus','Weitere Angaben zum Wasseraustritt (Etage, Name, Räume)','Wohnräume/Räume noch bewohnbar/nutzbar','Getroffene Not-/Sofortmaßnahmen'],
        'Ersatzpflicht' => ['Ersatzpflicht','Obliegenheitsverletzungen','Sicherheitsvorschriften verletzt'],
        'Schadenhöhe und Umfang' => ['Schadenhöhe','Reserveempfehlung','Vorsteuerabzugsberechtigung'],
        'Prüfung Versicherungssumme (überschlägig)' => ['Versicherungssumme ausreichend'],
        'Regress' => ['Regressmöglichkeiten','Wurde der schadenursächliche Gegenstand gesichert'],
        'Mehrfachversicherung' => ['Mehrfachversicherung möglich','Keine Mehrfachversicherung möglich weil'],
        'Bankverbindung / Zahlweise' => ['Zahlweise'],
        'Weitere Vorgehensweise' => ['Wir empfehlen'],
    ];
}

function gfRekonSectionValues(string $text, array $labels): array
{
    $values = [];
    foreach ($labels as $index => $label) {
        $needle = $label.':';
        $start = mb_strpos($text, $needle, 0, 'UTF-8');
        if ($start === false) {$values[$label] = ''; continue;}
        $valueStart = $start + mb_strlen($needle, 'UTF-8');
        $end = mb_strlen($text, 'UTF-8');
        for ($next = $index + 1; $next < count($labels); $next++) {
            $candidate = mb_strpos($text, $labels[$next].':', $valueStart, 'UTF-8');
            if ($candidate !== false) {$end = $candidate; break;}
        }
        $values[$label] = trim(mb_substr($text, $valueStart, $end - $valueStart, 'UTF-8'));
    }
    return $values;
}

function gfRekonValidateResult(array $result): array
{
    $schema = gfRekonSchema();
    $sections = is_array($result['sections'] ?? null) ? array_values($result['sections']) : [];
    $headings = array_map(static fn($section) => trim((string)($section['heading'] ?? '')), $sections);
    if ($headings !== array_keys($schema)) throw new RuntimeException('Rekon-QS-Sperre: Abschnitte oder Reihenfolge weichen vom Rekon-Formular ab.');
    foreach ($sections as $section) {
        $heading = trim((string)($section['heading'] ?? ''));
        $text = (string)($section['text'] ?? '');
        $offset = 0;
        foreach ($schema[$heading] as $label) {
            $position = mb_strpos($text, $label.':', $offset, 'UTF-8');
            if ($position === false) throw new RuntimeException('Rekon-QS-Sperre: Feld fehlt oder ist falsch angeordnet: '.$label.'.');
            $offset = $position + mb_strlen($label.':', 'UTF-8');
        }
    }
    return ['passed'=>true,'checks'=>['rekon_section_order','rekon_field_order','no_images','no_document_appendix']];
}

function gfRekonDocumentHtml(array $meta, array $result, string $userName): string
{
    $sectionsByHeading = [];
    foreach (($result['sections'] ?? []) as $section) $sectionsByHeading[trim((string)($section['heading'] ?? ''))] = (string)($section['text'] ?? '');
    $body = '';
    foreach (gfRekonSchema() as $heading => $labels) {
        $values = gfRekonSectionValues((string)($sectionsByHeading[$heading] ?? ''), $labels);
        $rows = '';
        foreach ($labels as $label) $rows .= '<tr><th>'.gfH($label).':</th><td>'.nl2br(gfH((string)($values[$label] ?? ''))).'</td></tr>';
        $body .= '<h2>'.gfH($heading).'</h2><table>'.$rows.'</table>';
    }
    $caseNo = gfH((string)($meta['schaden_nr'] ?? ''));
    $policy = gfH((string)($meta['versicherungsschein_nr'] ?? $meta['vertrags_nr'] ?? ''));
    $object = gfH((string)($meta['vn_objekt'] ?? $meta['versicherungsnehmer'] ?? ''));
    $place = gfH(trim(implode(' ', array_filter([(string)($meta['schaden_strasse'] ?? $meta['strasse'] ?? ''),(string)($meta['schaden_plz'] ?? $meta['plz'] ?? ''),(string)($meta['schaden_ort'] ?? $meta['ort'] ?? '')]))));
    return '<!doctype html><html><head><meta charset="utf-8"><title>Rekon-Schadenbericht</title><style>@page{size:A4;margin:18mm 17mm}body{font-family:Arial,sans-serif;font-size:10pt;line-height:1.35;color:#172b4d;margin:0}h1{font-size:18pt;letter-spacing:.04em;margin:0 0 14px;color:#142a50}h2{font-size:12pt;margin:20px 0 7px;color:#142a50;page-break-after:avoid}table{width:100%;border-collapse:collapse;table-layout:fixed;margin:0 0 8px}th,td{border:1px solid #e2e4e7;padding:6px 8px;vertical-align:top;text-align:left}th{width:36%;background:#efedef;font-weight:700}td{background:#fafafa;white-space:pre-wrap}.head{margin-bottom:18px}.head th{width:25%}.footer{margin-top:22px;padding-top:8px;border-top:1px solid #d5d8dd;font-size:8.5pt;color:#596577}</style></head><body><h1>Rekon-Schadenbericht</h1><table class="head"><tr><th>Schaden-Nr.</th><td>'.$caseNo.'</td><th>Versicherungs-/Vertrags-Nr.</th><td>'.$policy.'</td></tr><tr><th>Versicherungsnehmer/-in</th><td>'.$object.'</td><th>Besichtigungsort</th><td>'.$place.'</td></tr></table>'.$body.'<p class="footer">Erstellt am '.gfH(date('d.m.Y')).' durch '.gfH($userName).'. Ausgabe ohne Bilder und Dokumentanhänge.</p></body></html>';
}

// Zwei getrennte Erstbericht-Typen sicherstellen, sofern der Kern sie noch nicht enthält.
if (!str_contains($source, "'erstbericht_sv_gf'=>'Erstbericht SV-GF (QS Engel)'")) {
    $source = str_replace("'erstbericht'=>'Erstbericht'", "'erstbericht'=>'Allgemeiner Erstbericht','erstbericht_sv_gf'=>'Erstbericht SV-GF (QS Engel)'", $source);
}
if (!str_contains($source, "'rekon_schaden'=>'Rekon-Schadenbericht'")) {
    $source = str_replace("'erstbericht_sv_gf'=>'Erstbericht SV-GF (QS Engel)'", "'erstbericht_sv_gf'=>'Erstbericht SV-GF (QS Engel)','rekon_schaden'=>'Rekon-Schadenbericht'", $source);
}
if (!str_contains($source, "'erstbericht_sv_gf'=>['Versicherungsverhältnisse'")) {
    $source = str_replace(
        "'erstbericht'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress']",
        "'erstbericht'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress'],'erstbericht_sv_gf'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress']",
        $source
    );
}
if (!str_contains($source, "'rekon_schaden'=>['Basisdaten Erfassung'")) {
    $source = str_replace(
        "'erstbericht_sv_gf'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress']",
        "'erstbericht_sv_gf'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress'],'rekon_schaden'=>['Basisdaten Erfassung','Schadenhergang, Plausibilität und Ursache','Bestimmungswidriger Leitungswasseraustritt','Ersatzpflicht','Schadenhöhe und Umfang','Prüfung Versicherungssumme (überschlägig)','Regress','Mehrfachversicherung','Bankverbindung / Zahlweise','Weitere Vorgehensweise']",
        $source
    );
}
$source = str_replace(
    "'erstbericht'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress']",
    "'erstbericht'=>['Risiko','Schadenhergang','Schadenursache','Schadenumfang','Ersatzpflicht','Regress','Obliegenheitsverletzungen','Doppelversicherung','Polizeiliche Ermittlungen','Handlungsempfehlungen','Kalkulation']",
    $source
);
$source = str_replace(
    '$allowed=[\'dokumentenindex\',\'rechnungsregister\',\'erstbericht\',\'schadenprotokoll\'',
    '$allowed=[\'dokumentenindex\',\'rechnungsregister\',\'erstbericht\',\'erstbericht_sv_gf\',\'schadenprotokoll\'',
    $source
);
$source = str_replace(
    "'erstbericht_sv_gf','schadenprotokoll'",
    "'erstbericht_sv_gf','rekon_schaden','schadenprotokoll'",
    $source,
    $rekonAllowedCount
);
if ($rekonAllowedCount !== 1) throw new RuntimeException('Rekon-Schaden konnte nicht zur Dokumentauswahl hinzugefügt werden.');

// „Kalkulation“ erzeugt keinen starren Bericht, sondern einen editierbaren
// KI-Erstentwurf auf der vorhandenen Kalkulationsseite.
$calculationAllowedNeedle = "'schlussbericht','vorauszahlung','query_form'];if(\$folderId===''||!\$outputs)";
$calculationAllowedReplacement = "'schlussbericht','vorauszahlung','query_form','kalkulation'];if(\$folderId===''||!\$outputs)";
$source = str_replace($calculationAllowedNeedle, $calculationAllowedReplacement, $source, $calculationAllowedCount);
if ($calculationAllowedCount !== 1) throw new RuntimeException('Kalkulation konnte nicht zur Dokumentauswahl hinzugefügt werden.');

$calculationValidationNeedle = 'function gfEngelValidate(string $key,array $result,array $meta,string $instructions):array{if(gfExcelOutput($key))';
$calculationValidationReplacement = 'function gfEngelValidate(string $key,array $result,array $meta,string $instructions):array{if($key===\'kalkulation\'){$calculationState=gfCalculationDraftState($result,$meta,$instructions);return[\'passed\'=>true,\'checks\'=>!empty($calculationState[\'requiresManualCompletion\'])?[\'empty_editable_draft\',\'manual_completion_required\']:[\'documented_quantities\',\'documented_unit_prices\',\'source_per_position\',\'editable_calculation_draft\']];}if(gfExcelOutput($key))';
$source = str_replace($calculationValidationNeedle, $calculationValidationReplacement, $source, $calculationValidationCount);
if ($calculationValidationCount !== 1) throw new RuntimeException('Kalkulations-QS konnte nicht angebunden werden.');
$rekonValidationNeedle = "function gfEngelValidate(string \$key,array \$result,array \$meta,string \$instructions):array{if(\$key==='kalkulation')";
$rekonValidationReplacement = "function gfEngelValidate(string \$key,array \$result,array \$meta,string \$instructions):array{if(\$key==='rekon_schaden')return gfRekonValidateResult(\$result);if(\$key==='kalkulation')";
$source = str_replace($rekonValidationNeedle, $rekonValidationReplacement, $source, $rekonValidationCount);
if ($rekonValidationCount !== 1) throw new RuntimeException('Rekon-Formular-QS konnte nicht angebunden werden.');
$protocolValidationNeedle = "function gfEngelValidate(string \$key,array \$result,array \$meta,string \$instructions):array{if(\$key==='rekon_schaden')";
$protocolValidationReplacement = "function gfEngelValidate(string \$key,array \$result,array \$meta,string \$instructions):array{if(\$key==='schadenprotokoll')return gfProtocolValidateResult(\$result);if(\$key==='rekon_schaden')";
$source = str_replace($protocolValidationNeedle, $protocolValidationReplacement, $source, $protocolValidationCount);
if ($protocolValidationCount !== 1) throw new RuntimeException('Schadenprotokoll-QS konnte nicht angebunden werden.');

$calculationBkiNeedle = '$bkiRequested=preg_match(\'/\\bBKI\\b/ui\',$instructions)===1;';
$calculationBkiReplacement = '$bkiRequested=in_array(\'kalkulation\',$outputs,true)||preg_match(\'/\\bBKI\\b/ui\',$instructions)===1;';
$source = str_replace($calculationBkiNeedle, $calculationBkiReplacement, $source, $calculationBkiCount);
if ($calculationBkiCount !== 1) throw new RuntimeException('BKI-Grundlage der Kalkulation konnte nicht angebunden werden.');

$calculationPromptNeedle = '$bkiRule=$bkiRequested?';
$calculationPromptReplacement = <<<'PHP_CODE'
if($key==='kalkulation')$responseRule=' Antworte ausschließlich als JSON {"summary":"...","items":[{"position_code":"BKI-Position oder leer","description":"konkrete Leistung","quantity":1.0,"unit":"m²|m|St|Std|psch","unit_price":123.45,"regional_factor":1.0,"source_name":"exakter Dateiname oder BKI-Quelle","source_page":"Seite"}],"vat":19,"assumptions":["..."],"open_points":["..."]}. Erstelle eine erste, auf der vorhandenen Kalkulationsseite weiter bearbeitbare Schadenkalkulation. Werte Schadenhergang, Schadenumfang, Aufmaße, Kostenvoranschläge, Rechnungen und die beigefügten BKI-Altbau-Quellen vollständig aus. Jede Position benötigt eine konkrete Leistung, eine belegte Menge mit Einheit, einen belastbaren Einheitspreis und die exakte Quelle. Einheitspreise ausschließlich aus BKI oder eindeutig belegten KVA-/Rechnungspositionen übernehmen. Mengen ausschließlich aus belegten Aufmaßen oder ausdrücklich als vorläufige Annahme im Feld assumptions verwenden. Keine Werte erfinden. Nicht belastbare Positionen nicht kalkulieren, sondern als open_points ausweisen. Vorgaben und Ergänzungen aus dem Arbeitsauftrag verbindlich berücksichtigen. Die Ausgabe ist ausdrücklich ein fachlich zu prüfender Erstentwurf und keine Freigabe.';$bkiRule=$bkiRequested?
if($key==='schadenprotokoll')$responseRule=' Antworte ausschließlich als JSON {"summary":"","form_fields":{"meldetag":"","gespraechspartner":"","schadenhergang":"","weitere_erklaerungen":"","polizei":"","vorversicherung":"","vorschaeden":"","weitere_versicherungen":"","eigentumsverhaeltnisse":"","zahlungsadresse":"","sachverstaendigenverfahren":"","freigaben":"","regress":"","verursacher":"","bauteil_eigentuemer":"","mieterdaten":"","haftpflichtversicherung":"","erforderliche_unterlagen":"","kostenvoranschlaege":"","bemerkungen":"","vereinbarungen":""},"sections":[],"open_points":["..."]}. Befülle ausschließlich eindeutig aus der Akte belegte Felder; unbekannte Angaben bleiben als leere Zeichenkette erhalten. Verwende die Feldnamen exakt wie vorgegeben. Der Schadenhergang enthält den chronologischen und technischen Sachverhalt einschließlich des belegten Schadenbildes, der Ursache, der betroffenen Bereiche und der Sofortmaßnahmen. Unterschriften, Empfangsbestätigungen und Checkboxen niemals stellvertretend ausfüllen. Keine zusätzlichen Abschnittsüberschriften erfinden.';
PHP_CODE;
$source = str_replace($calculationPromptNeedle, trim($calculationPromptReplacement), $source, $calculationPromptCount);
if ($calculationPromptCount !== 1) throw new RuntimeException('KI-Auftrag für die Kalkulationsseite konnte nicht angebunden werden.');

// Die aktuelle Gesamtreserve direkt aus dem Regulierungsauftrag lesen.
function gfPortalCurrentReserve(array $caseFiles): ?string
{
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) return null;
    foreach ($caseFiles as $file) {
        $name = (string)($file['name'] ?? '');
        if (!preg_match('/regulierungsauftrag.*\.xls(?:x|m)$/ui', $name)) continue;
        $download = gfDriveDownload($file);
        $bytes = is_array($download) ? (string)($download['bytes'] ?? '') : '';
        if ($bytes === '') continue;
        $tmp = tempnam(sys_get_temp_dir(), 'svnet-reserve-');
        if ($tmp === false) continue;
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {@unlink($tmp);continue;}
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $dom = new DOMDocument();
            if (@$dom->loadXML($sharedXml)) {
                $xp = new DOMXPath($dom);
                $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                foreach ($xp->query('//x:si') ?: [] as $si) {
                    $value = '';
                    foreach ($xp->query('.//x:t', $si) ?: [] as $textNode) $value .= $textNode->textContent;
                    $shared[] = trim($value);
                }
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);
        if (!is_string($sheetXml) || $sheetXml === '') continue;
        $dom = new DOMDocument();
        if (!@$dom->loadXML($sheetXml)) continue;
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($xp->query('//x:row') ?: [] as $row) {
            $values = [];
            foreach ($xp->query('./x:c', $row) ?: [] as $cell) {
                $type = (string)$cell->getAttribute('t');
                $raw = trim((string)($xp->query('./x:v', $cell)?->item(0)?->textContent ?? ''));
                if ($type === 's' && ctype_digit($raw)) $raw = (string)($shared[(int)$raw] ?? '');
                elseif ($type === 'inlineStr') $raw = trim((string)($xp->query('./x:is/x:t', $cell)?->item(0)?->textContent ?? ''));
                $values[] = trim($raw);
            }
            foreach ($values as $index => $value) {
                if (gfNorm($value) !== 'gesamtreserveaktuell') continue;
                $reserve = trim((string)($values[$index + 1] ?? ''));
                if ($reserve !== '' && is_numeric(str_replace(',', '.', $reserve))) {
                    return number_format((float)str_replace(',', '.', $reserve), 2, ',', '.').' EUR';
                }
            }
        }
    }
    return null;
}
$caseFilesNeedle = '$knowledgeFiles=$rekonOnly?[]:gfKnowledgeFiles();';
$caseFilesReplacement = "\$portalReserve=gfPortalCurrentReserve(\$caseFiles);if(\$portalReserve!==null)\$meta['reserve']=\$portalReserve;".$caseFilesNeedle;
if (!str_contains($source, 'gfPortalCurrentReserve($caseFiles)')) {
    $source = str_replace($caseFilesNeedle, $caseFilesReplacement, $source, $countReserveBinding);
    if ($countReserveBinding !== 1) throw new RuntimeException('Aktuelle Gesamtreserve konnte nicht sicher angebunden werden.');
}

// Sämtliche relevanten Fallunterlagen ohne willkürliche Dateigrenze auswerten.
// Pro Gruppe werden nur wenige Originale gleichzeitig hochgeladen und sofort
// in einen dateibezogenen Evidenzbestand überführt. So bleiben auch Akten mit
// 80 bis 100 PDF-Dateien vollständig, ohne den Server zu überlasten.
$caseUploadNeedle = "\$caseRefs=[];foreach(\$caseFiles as\$f){\$r=gfOpenAIUploadDriveFile(\$f,'case');if(\$r)\$caseRefs[]=\$r;}if(!\$caseRefs)throw new RuntimeException('Fallunterlagen konnten der KI nicht bereitgestellt werden.');gfJobUpdate(\$jobId,'running',18,'Regelwerke werden geladen.');";
$caseUploadReplacement = <<<'PHP_CODE'
gfJobUpdate($jobId,'running',8,$rekonOnly?'Rekon-Unterlagen werden vollständig und ohne Bilder ausgewertet.':'Fallunterlagen und Schadenbilder werden vollständig ausgewertet.');
$evidenceStartedAt=microtime(true);
$caseEvidence=[];
$caseEvidenceEntries=[];
$caseEvidenceByKey=[];
$processedCaseFiles=0;
$evidenceApiCalls=0;
foreach($caseFiles as$caseFile){$cacheKey=gfEvidenceFileCacheKey($caseFile);$cachedEvidence=json_decode(gfSettingGet($cacheKey,'{}'),true);if(gfEvidenceIsUsable($cachedEvidence))$caseEvidenceByKey[$cacheKey]=$cachedEvidence;}
foreach(array_chunk($caseFiles,4)as$legacyChunk){$needsMigration=false;foreach($legacyChunk as$legacyFile){if(!isset($caseEvidenceByKey[gfEvidenceFileCacheKey($legacyFile)])){$needsMigration=true;break;}}if(!$needsMigration)continue;$legacySignature=array_map(static fn(array$f):array=>['id'=>(string)($f['id']??''),'modified'=>(string)($f['modifiedTime']??''),'name'=>(string)($f['name']??''),'mime'=>(string)($f['mimeType']??'')],$legacyChunk);$legacyKey='gf_evidence_v3_'.hash('sha256',json_encode($legacySignature,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$legacyEvidence=json_decode(gfSettingGet($legacyKey,'{}'),true);if(!gfEvidenceIsUsable($legacyEvidence))continue;$legacySources=[];foreach($legacyChunk as$legacyFile){$legacyFile['_evidence_name']=gfOpenAIUploadName((string)($legacyFile['name']??''),(string)($legacyFile['mimeType']??''));$legacySources[]=$legacyFile;}foreach(gfEvidenceSplitBySource($legacyEvidence,$legacySources)as$migratedKey=>$migratedEvidence){if(isset($caseEvidenceByKey[$migratedKey]))continue;gfSettingSet($migratedKey,json_encode($migratedEvidence,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$caseEvidenceByKey[$migratedKey]=$migratedEvidence;}}
$pendingCaseFiles=[];foreach($caseFiles as$caseIndex=>$caseFile){$cacheKey=gfEvidenceFileCacheKey($caseFile);if(isset($caseEvidenceByKey[$cacheKey])){$caseEvidenceEntries[]=['index'=>$caseIndex,'evidence'=>$caseEvidenceByKey[$cacheKey]];$processedCaseFiles++;}else{$caseFile['_case_index']=$caseIndex;$pendingCaseFiles[]=$caseFile;}}
$evidenceCacheHits=$processedCaseFiles;$evidenceCacheMisses=count($pendingCaseFiles);if($evidenceCacheHits>0)gfJobUpdate($jobId,'running',8+min(18,(int)floor(($processedCaseFiles/max(1,count($caseFiles)))*18)),'Fallunterlagen aus stabilem Zwischenspeicher übernommen: '.$processedCaseFiles.' von '.count($caseFiles).'.');
$pendingChunks=array_chunk($pendingCaseFiles,4);foreach($pendingChunks as$chunkIndex=>$caseChunk){$chunkRefs=[];$uploadedSources=[];foreach($caseChunk as$caseFile){gfJobUpdate($jobId,'running',8+min(17,(int)floor(($processedCaseFiles/max(1,count($caseFiles)))*18)),'Fallunterlage wird vorbereitet: '.($processedCaseFiles+count($chunkRefs)+1).' von '.count($caseFiles).'.');$caseRef=gfOpenAIUploadDriveFile($caseFile,'case');if(!$caseRef)continue;$caseFile['_evidence_name']=gfOpenAIUploadName((string)($caseRef['name']??''),(string)($caseRef['mime']??''));$uploadedSources[]=$caseFile;$chunkRefs[]=$caseRef;}if(!$chunkRefs)continue;gfJobUpdate($jobId,'running',8+min(17,(int)floor(($processedCaseFiles/max(1,count($caseFiles)))*18)),'Neue oder geänderte Dateigruppe '.($chunkIndex+1).' von '.count($pendingChunks).' wird ausgewertet.');$evidenceContent=[['type'=>'input_text','text'=>'Lies jede beigefügte Datei beziehungsweise jedes Bild vollständig und erstelle ausschließlich JSON mit dieser Struktur: {"files":[{"name":"exakter Dateiname","document_type":"Dokumentart oder Schadenfoto","facts":["jede relevante Tatsachenangabe mit Einheit und Kontext"],"amounts":[{"description":"Position oder Summe","net":null,"tax":null,"gross":null}],"measurements":[{"description":"Maß oder Fläche","value":"exakter Wert mit Einheit","measurement_type":"Fläche|Länge|Feuchte|Druck|sonstiges"}],"visual_findings":["sichtbarer Schadenbereich, Bauteil, Raumzuordnung und erkennbarer Umfang"],"participants":["Name und Funktion"],"open_points":[]}]} . Erfinde nichts. Bilder einzeln auswerten: sichtbare Schäden, betroffene Bauteile, Raumzuordnung, Maßstäbe, Beschriftungen und nur tatsächlich ablesbare Maße erfassen. Keine verdeckten Schäden, Maße oder Mengen aus Perspektive oder Erfahrungswerten schätzen. Logos, Signaturen, Profilbilder und dekorative Bilder ignorieren. Kostenvoranschläge und Rechnungen vollständig mit Aussteller, Nummer, Datum, sämtlichen wesentlichen Hauptleistungsgruppen sowie Netto-, Umsatzsteuer- und Bruttosumme erfassen. Werte aus DIGITS oder sonstigen Feuchtemessungen nur intern als measurement_type Feuchte kennzeichnen; sie dürfen später weder als Fläche noch als Zahlenreihe in den Bericht gelangen. Jede Datei muss genau einmal im files-Array erscheinen.']];foreach($chunkRefs as$chunkRef)$evidenceContent[]=gfCalculationInputPart($chunkRef);$evidence=gfOpenAI($evidenceContent,'Du extrahierst vollständig und quellengetreu Tatsachen aus deutschen Schadenakten und Schadenbildern. Antworte ausschließlich als valides JSON. Keine Zusammenfassung über mehrere Dateien; jede Datei und jedes Bild einzeln und vollständig erfassen.',(int)env('OPENAI_EVIDENCE_MAX_OUTPUT_TOKENS','7000'));$evidenceApiCalls++;$splitEvidence=gfEvidenceSplitBySource($evidence,$uploadedSources);if(count($splitEvidence)===count($uploadedSources)){foreach($uploadedSources as$uploadedSource){$cacheKey=gfEvidenceFileCacheKey($uploadedSource);$singleEvidence=$splitEvidence[$cacheKey];gfSettingSet($cacheKey,json_encode($singleEvidence,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$caseEvidenceEntries[]=['index'=>(int)$uploadedSource['_case_index'],'evidence'=>$singleEvidence];}}else{$caseEvidenceEntries[]=['index'=>(int)min(array_column($uploadedSources,'_case_index')),'evidence'=>$evidence];}$processedCaseFiles+=count($uploadedSources);gfJobUpdate($jobId,'running',8+min(18,(int)floor(($processedCaseFiles/max(1,count($caseFiles)))*18)),'Fallunterlagen und Schadenbilder ausgewertet: '.$processedCaseFiles.' von '.count($caseFiles).'.');unset($chunkRefs,$uploadedSources,$evidenceContent,$evidence,$splitEvidence);}usort($caseEvidenceEntries,static fn(array$a,array$b):int=>$a['index']<=>$b['index']);foreach($caseEvidenceEntries as$caseEvidenceEntry)$caseEvidence[]=$caseEvidenceEntry['evidence'];$evidencePerformance=['duration_seconds'=>round(microtime(true)-$evidenceStartedAt,3),'cache_hits'=>$evidenceCacheHits,'cache_misses'=>$evidenceCacheMisses,'api_calls'=>$evidenceApiCalls,'concurrency'=>1];if(!$caseEvidence)throw new RuntimeException('Fallunterlagen konnten der KI nicht bereitgestellt werden.');gfJobUpdate($jobId,'running',28,'Regelwerke werden geladen.');
PHP_CODE;
$caseUploadReplacement = str_replace(
    "'Fallunterlage wird vorbereitet: '.(\$processedCaseFiles+count(\$chunkRefs)+1).' von '.count(\$caseFiles).'.'",
    "'Datei '.(\$processedCaseFiles+count(\$chunkRefs)+1).' von '.count(\$caseFiles).' · Gruppe '.(\$chunkIndex+1).' von '.count(\$pendingChunks).' · '.(string)(\$caseFile['name']??'Unterlage').' wird vorbereitet.'",
    $caseUploadReplacement
);
$caseUploadReplacement = str_replace(
    "'Neue oder geänderte Dateigruppe '.(\$chunkIndex+1).' von '.count(\$pendingChunks).' wird ausgewertet.'",
    "'Gruppe '.(\$chunkIndex+1).' von '.count(\$pendingChunks).' wird ausgewertet · Dateien '.(\$processedCaseFiles+1).' bis '.(\$processedCaseFiles+count(\$uploadedSources)).' von '.count(\$caseFiles).'.'",
    $caseUploadReplacement
);
$source = str_replace($caseUploadNeedle, trim($caseUploadReplacement), $source, $countCaseUpload);
if ($countCaseUpload !== 1) throw new RuntimeException('Chargenweise Aktenauswertung konnte nicht sicher angebunden werden.');
$sourceNamesNeedle = '$sourceNames=array_map(fn($r)=>(string)$r[\'name\'],$caseRefs);';
$sourceNamesReplacement = '$sourceNames=array_map(fn($f)=>(string)($f[\'name\']??\'Quelle\'),$caseFiles);';
$source = str_replace($sourceNamesNeedle, $sourceNamesReplacement, $source, $countSourceNames);
if ($countSourceNames !== 1) throw new RuntimeException('Vollständige Quellenliste konnte nicht sicher angebunden werden.');
$directCaseFilesNeedle = "foreach(\$caseRefs as\$r)\$content[]=['type'=>'input_file','file_id'=>\$r['file_id']];";
$evidenceBinding = "foreach(\$caseEvidence as\$evidenceIndex=>\$evidence)\$content[]=['type'=>'input_text','text'=>'Vollständiger dateibezogener Evidenzbestand, Gruppe '.(\$evidenceIndex+1).':\\n'.json_encode(\$evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];";
$source = str_replace($directCaseFilesNeedle, $evidenceBinding, $source, $countEvidenceBinding);
if ($countEvidenceBinding !== 1) throw new RuntimeException('Evidenzbestand konnte nicht sicher an die Dokumenterstellung angebunden werden.');

// SV-GF-Template nur injizieren, wenn der Kern noch keine Sonderbehandlung kennt.
if (!str_contains($source, "if(\$key==='erstbericht_sv_gf')")) {
    $sig = 'function gfTemplateFile(string $key,array $meta,string $instructions):?array{';
    $rep = "function gfTemplateFile(string \$key,array \$meta,string \$instructions):?array{if(\$key==='erstbericht_sv_gf'){foreach(gfDriveWalk(GF_KNOWLEDGE_FOLDER_ID,6,250)as\$f)if(strcasecmp((string)(\$f['name']??''),'2026-08-05_GF_Erstbericht_Vorlage_QS_Engel.docx')===0)return\$f;throw new RuntimeException('SV-GF-Erstbericht-Blanco nach QS Engel nicht gefunden.');}";
    $source = str_replace($sig, $rep, $source);
}
$templateSignature = 'function gfTemplateFile(string $key,array $meta,string $instructions):?array{';
if (!str_contains($source, "if(\$key==='erstbericht')return null;")) {
    $templateNeedle = str_contains($source, $templateSignature."if(\$key==='erstbericht_sv_gf')")
        ? $templateSignature."if(\$key==='erstbericht_sv_gf')"
        : $templateSignature;
    $templateReplacement = $templateSignature."if(\$key==='erstbericht')return null;"
        . ($templateNeedle !== $templateSignature ? "if(\$key==='erstbericht_sv_gf')" : '');
    $source = str_replace($templateNeedle, $templateReplacement, $source);
}
$engelSignature = <<<'PHP_CODE'
function gfEngelReport(string $key):bool{return in_array($key,['erstbericht','zwischenbericht','schlussbericht'],true);}
PHP_CODE;
$engelReplacement = <<<'PHP_CODE'
function gfEngelReport(string $key):bool{return in_array($key,['erstbericht_sv_gf','zwischenbericht','schlussbericht'],true);}
PHP_CODE;
$source = str_replace($engelSignature, $engelReplacement, $source);

// Beim allgemeinen Erstbericht die fachlichen Kapitel deterministisch benennen.
// Inhalt und Reihenfolge bleiben erhalten; die Engel-Blanco-QS gilt nur für SV-GF.
$resultNeedle = '$result=gfEngelPrepare($key,gfOpenAI($content,$system),$meta);$contentQs=gfEngelValidate($key,$result,$meta,$instructions);';
$resultReplacement = <<<'PHP_CODE'
$result=gfEngelPrepare($key,$key==='kalkulation'?gfGenerateCalculation($caseEvidence,$meta,$instructions,$bki):gfOpenAI($content,$system,$key==='rekon_schaden'?12000:null),$meta);$resultJson=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(is_string($resultJson)&&str_contains($resultJson,'SparkassenVersicherung')){$normalizedResult=json_decode(str_replace('SparkassenVersicherung','Sparkassenversicherung',$resultJson),true);if(is_array($normalizedResult))$result=$normalizedResult;}if($key==='erstbericht'){$generalReview=$content;$generalReview[]=['type'=>'input_text','text'=>"REDAKTIONELLE SCHLUSS-QS: Überarbeite den folgenden Entwurf vollständig und gib wieder ausschließlich das verlangte JSON aus. Die Abschnitte Schadenhergang, Schadenursache und Schadenumfang müssen getrennt, eigenständig und anhand aller verfügbaren Einzelangaben eingehend ausgearbeitet sein. Schadenhergang enthält nur Chronologie, Wahrnehmung, Meldung, Entwicklung, Sofortmaßnahmen, Termine und Abstimmungen. Bei jedem im Arbeitsauftrag erwähnten Termin müssen alle dort genannten Teilnehmer enthalten sein; Namen und Funktionen aus den übermittelten System- und Falldaten auflösen, niemals einen genannten Beteiligten weglassen und niemals einen Namen erfinden. Schadenursache enthält nur schadenursächliches Bauteil, technischen Mechanismus, Untersuchungen, Nachweise, Feststellungssicherheit und verbleibende technische Unklarheiten. Schadenumfang enthält nur betroffene Räume, Bauteile und Konstruktionen, Schaden- und Feuchtebilder, erforderliche Öffnungs-, Trocknungs-, Rückbau- und Wiederherstellungsbereiche sowie belastbare Maße und Aufmaße. Prüfe vor Ausgabe ausdrücklich, ob in den Falldaten Raumflächen, Boden-, Wand- oder Deckenflächen, Längen, Umfänge oder sonstige Mengen mit Einheiten vorhanden sind; sämtliche schadenrelevanten belastbaren Werte müssen im Schadenumfang erscheinen. Leitungslängen und KVA-Mengen ersetzen kein vorhandenes Raum- oder Schadenaufmaß. Pauschale Kurzformulierungen in diesen drei Abschnitten sind unzulässig, sofern weitere relevante Falldaten vorliegen. Entferne ausnahmslos Wiederholungen zwischen den Kapiteln. Jeder Sachverhalt darf nur in seinem zuständigen Kapitel stehen. Das Aufmaß ist als sachverständige Feststellung mit Rechenansätzen darzustellen, jedoch vollständig quellenneutral: Im Aufmaßabsatz keine Firmen-, Dokument-, Angebots-, KVA-, Gutachten-, Prüfbericht- oder Produktnamen und keine Herkunftsformulierungen. Firmen- und Belegnamen sind nur im Kapitel Kalkulation zulässig. Externe Prüfberichte und deren Regulierungsempfehlungen sind nicht maßgeblich. Ohne ausdrückliche abweichende Vorgabe im aktuellen Arbeitsauftrag ist ausschließlich der vollständige Original-KVA als Bewertungs- und Freigabebetrag anzusetzen. Kürzungen aus PropertyExpert oder anderen Fremdprüfungen nicht übernehmen und nicht als eigenen Prüfstand darstellen.  Überarbeite den Text als eigenständige sachverständige Bewertung und nicht als bloßes Aktenreferat. Prüfe die innere technische Logik: Ursache, Leitungsweg, Öffnungsbedarf, Trocknungsfähigkeit, Rückbau, Wiederherstellung und wirtschaftliche Lösungsvariante müssen widerspruchsfrei zusammenpassen. Eine Bypass- oder Alternativlösung ist technisch und wirtschaftlich fallbezogen zu begründen und ein Zustimmungsvorbehalt vollständig zu erhalten. Raumgrößen nicht ohne eindeutigen Beleg als vollständig geschädigte Flächen bezeichnen. Raumgrundflächen ausschließlich als erfasste Raumgrundflächen oder Raumgrößen benennen; Formulierungen wie betroffen sind Räume mit insgesamt, betroffene Fläche oder Schadenfläche sind dafür unzulässig, solange keine tatsächliche flächige Betroffenheit belegt ist. Tatsächliche Schadenflächen separat ausweisen oder ausdrücklich als noch nicht belastbar abgegrenzt kennzeichnen. Nur gleichartige, überschneidungsfreie Maße addieren; unterschiedliche Flächenarten, Umfänge und Längen getrennt ausweisen. Bei eingeschränkt trocknungsfähigen Bekleidungen klar zwischen trocknungsfähigen Bereichen und erforderlichem Ausbau beziehungsweise Erneuerung unterscheiden. Bereits ausgeführte, angebotene, bezahlte und noch fehlende Leistungen eindeutig auseinanderhalten. Entferne unverbindliche Füllsätze wie der KVA ist abzugleichen, Zahlung fortführen oder weiteres abstimmen, sobald eine konkrete Bewertung möglich ist. Kalkulation nicht nur aufzählen: eigenes Prüfergebnis formulieren und ohne ausdrückliche eigene Kürzung den vollständigen Original-KVA als maßgeblichen Ansatz benennen. Reserve ausschließlich aus dem aktuellen Regulierungsauftrag, Feld Gesamtreserve aktuell beziehungsweise Erstreserve, übernehmen; niemals Selbstbehalt oder KVA als Reserve.\n\nZU ÜBERARBEITENDER ENTWURF:\n".json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];$result=gfEngelPrepare($key,gfOpenAI($generalReview,$system),$meta);$generalHeadings=gfHeadings($key);$generalSections=is_array($result['sections']??null)?array_values($result['sections']):[];if(count($generalSections)!==count($generalHeadings))throw new RuntimeException('Allgemeiner Erstbericht unvollständig: Erwartet werden '.count($generalHeadings).' fachliche Fließtextabschnitte.');foreach($generalHeadings as$generalIndex=>$generalHeading){$generalSections[$generalIndex]['heading']=$generalHeading;$generalSections[$generalIndex]['text']=str_ireplace(['schaden ursächlich','schaden ursächliche','schaden ursächlichen'],['schadenursächlich','schadenursächliche','schadenursächlichen'],(string)($generalSections[$generalIndex]['text']??''));}$result['sections']=$generalSections;}$contentQs=gfEngelValidate($key,$result,$meta,$instructions);
PHP_CODE;
$resultReplacement = str_replace('$resultJson=', '$result=gfApplySavedCalculationToSchlusserklaerung($key,$result,$folderId,$instructions);$resultJson=', $resultReplacement, $savedCalculationHookCount);
if ($savedCalculationHookCount !== 1) throw new RuntimeException('Gespeicherte Kalkulation konnte nicht an die Schlusserklärung angebunden werden.');
if (!str_contains($source, 'Allgemeiner Erstbericht unvollständig:')) {
    $source = str_replace($resultNeedle, trim($resultReplacement), $source, $countGeneralPrepare);
    if ($countGeneralPrepare !== 1) {
        throw new RuntimeException('Allgemeiner Erstbericht konnte nicht sicher an die QS angebunden werden.');
    }
}

// Kopfangaben des allgemeinen Erstberichts aus den Originalunterlagen verifizieren.
// Die KI darf nur ausdrücklich belegte Werte liefern; leere Werte verändern die Falldaten nicht.
$generalReviewCall = '$result=gfEngelPrepare($key,gfOpenAI($generalReview,$system),$meta);';
$generalReviewCallWithHeader = <<<'PHP_CODE'
$generalReview[]=['type'=>'input_text','text'=>'VERBINDLICHE KOPFPRÜFUNG: Gib zusätzlich case_header als JSON-Objekt mit den Schlüsseln versicherungsnehmer, vn_strasse, vn_plz, vn_ort, schaden_strasse, schaden_plz und schaden_ort aus. Ermittle jeden Wert ausschließlich aus eindeutigen Originalunterlagen. Versicherungsnehmer ist der Name beziehungsweise die Firma, nicht nur Straße oder Objektbezeichnung. VN-Anschrift und Schadenort strikt trennen. Gib bei fehlendem eindeutigen Nachweis für einen Einzelwert eine leere Zeichenfolge aus; nichts ergänzen oder kombinieren. Übernimm niemals ein Feld, das mehrere Adressvarianten, Zusätze wie Im Wohnbau, Trennstriche oder widersprüchliche Orts- beziehungsweise PLZ-Angaben zusammenfügt. Schadenstraße enthält nur Straße und Hausnummer, Schaden-PLZ nur die fünfstellige PLZ und Schadenort nur den Ortsnamen.'];$result=gfEngelPrepare($key,gfOpenAI($generalReview,$system),$meta);if(is_array($result['case_header']??null)){$verified=$result['case_header'];$headerMap=['versicherungsnehmer'=>'vn_objekt','vn_strasse'=>'strasse','vn_plz'=>'plz','vn_ort'=>'ort','schaden_strasse'=>'schaden_strasse','schaden_plz'=>'schaden_plz','schaden_ort'=>'schaden_ort'];foreach($headerMap as$from=>$to){$value=trim(preg_replace('/\s+/u',' ',(string)($verified[$from]??''))??'');if(mb_strlen($value,'UTF-8')<=160)$meta[$to]=$value;}}
PHP_CODE;
$source = str_replace($generalReviewCall, trim($generalReviewCallWithHeader), $source, $countGeneralHeader);
if ($countGeneralHeader !== 1) throw new RuntimeException('Kopfprüfung des allgemeinen Erstberichts konnte nicht sicher angebunden werden.');

// Nachtragsausgabe ohne automatisch angehängte Quellen-/Regelwerkslisten.
if (!str_contains($source, "<title>Nachtrag</title>")) {
    $sig = 'function gfDocumentHtml(string $title,array $meta,array $result,array $sources,array $rules,string $userName):string{';
    $rep = <<<'PHP_CODE'
function gfDocumentHtml(string $title,array $meta,array $result,array $sources,array $rules,string $userName):string{if($title==='Allgemeiner Erstbericht'){$text='';foreach(($result['sections']??[])as$s){$h=trim((string)($s['heading']??''));$t=trim((string)($s['text']??''));if($h!==''&&$t!=='')$text.='<h2>'.gfH($h).'</h2><p>'.nl2br(gfH($t)).'</p>';}$caseNo=gfH((string)($meta['schaden_nr']??''));$policy=gfH((string)($meta['versicherungsschein_nr']??''));$object=gfH((string)($meta['vn_objekt']??$meta['versicherungsnehmer']??''));$damageStreet=trim((string)($meta['schaden_strasse']??''));$damageZip=trim((string)($meta['schaden_plz']??''));$damageCity=trim((string)($meta['schaden_ort']??''));$place=gfH(trim(($damageStreet!==''?$damageStreet:(string)($meta['strasse']??'')).' '.($damageZip!==''?$damageZip:(string)($meta['plz']??'')).' '.($damageCity!==''?$damageCity:(string)($meta['ort']??''))));return '<!doctype html><html><head><meta charset="utf-8"><title>Allgemeiner Erstbericht</title><style>body{font-family:Arial,sans-serif;font-size:11pt;line-height:1.5;margin:2cm;color:#111}h1{font-size:17pt;margin:0 0 18px}h2{font-size:11pt;text-decoration:underline;margin:18px 0 7px}p{margin:0 0 11px}.meta{margin-bottom:18px}</style></head><body><h1>Allgemeiner Erstbericht</h1><p class="meta"><strong>Schaden-Nr.:</strong> '.$caseNo.($policy!==''?'<br><strong>Versicherungsschein-Nr.:</strong> '.$policy:'').($object!==''?'<br><strong>Versicherungsnehmer / Objekt:</strong> '.$object:'').($place!==''?'<br><strong>Schadenort:</strong> '.$place:'').'</p>'.$text.'<p>Christian Wächter<br>Sachverständiger &amp; Großschadenregulierer<br>DIN EN ISO/IEC 17024 zertifiziert<br>https://www.sv-netzwerk.eu/</p></body></html>';}if($title==='Nachtrag / Stellungnahme'){$text='';foreach(($result['sections']??[])as$s){$t=trim((string)($s['text']??''));if($t!=='')$text.='<p>'.nl2br(gfH($t)).'</p>';}$caseNo=gfH((string)($meta['schaden_nr']??''));$object=gfH((string)($meta['vn_objekt']??$meta['versicherungsnehmer']??''));return '<!doctype html><html><head><meta charset="utf-8"><title>Nachtrag</title><style>body{font-family:Arial,sans-serif;font-size:11pt;line-height:1.5;margin:2cm;color:#111}h1{font-size:16pt;margin:0 0 18px}p{margin:0 0 12px}.meta{margin-bottom:18px}</style></head><body><h1>Nachtrag</h1><p class="meta"><strong>Schaden-Nr.:</strong> '.$caseNo.($object!==''?'<br><strong>VN / Objekt:</strong> '.$object:'').'</p>'.$text.'<p>Mit freundlichen Grüßen<br><br>Christian Wächter<br>Sachverständiger &amp; Großschadenregulierer<br>DIN EN ISO/IEC 17024 zertifiziert<br>https://www.sv-netzwerk.eu/</p></body></html>';}
PHP_CODE;
    $source = str_replace($sig, $rep, $source);
}

// Rekon wird bewusst ohne externes Blanco als deterministische Word-HTML-Datei
// erzeugt. Das feste Schema verhindert Bild-, Foto- und Dokumentanhänge.
$rekonDocumentSignature = 'function gfDocumentHtml(string $title,array $meta,array $result,array $sources,array $rules,string $userName):string{';
$rekonDocumentReplacement = $rekonDocumentSignature."if(\$title==='Rekon-Schadenbericht')return gfRekonDocumentHtml(\$meta,\$result,\$userName);";
$source = str_replace($rekonDocumentSignature, $rekonDocumentReplacement, $source, $rekonDocumentCount);
if ($rekonDocumentCount !== 1) throw new RuntimeException('Rekon-Word-Ausgabe konnte nicht angebunden werden.');

// Widersprüche und Regress nur fachlich relevant ausgeben.
$source = str_replace('Widersprüche ausdrücklich benennen.','Widersprüche nur benennen, wenn sie für den konkreten Arbeitsauftrag oder die Entscheidung relevant sind.',$source);
$source = str_replace('Bei Berichten Regressaussage nicht vergessen.','Regressaussagen nur in Erst-, Zwischen- und Schlussberichten oder bei ausdrücklichem Arbeitsauftrag aufnehmen.',$source);

// Harte Falltrennung vor jeder fachlichen QS: Fremde Schaden-/Vertragsnummern
// und eine zur Falldatei widersprüchliche Schadenart dürfen nicht ausgegeben
// oder als Dokument gespeichert werden.
$caseIsolationNeedle = '$result=gfEngelPrepare($key,$key===\'kalkulation\'?gfGenerateCalculation($caseEvidence,$meta,$instructions,$bki):gfOpenAI($content,$system,$key===\'rekon_schaden\'?12000:null),$meta);';
$caseIsolationReplacement = $caseIsolationNeedle.'$caseIsolationQs=gfValidateCaseIsolation($result,$meta,$sourceNames,$instructions,$caseEvidence);';
$source = str_replace($caseIsolationNeedle, $caseIsolationReplacement, $source, $caseIsolationCount);
if ($caseIsolationCount !== 1) throw new RuntimeException('Falltrennungs-Sperre konnte nicht sicher an die Dokumenterstellung angebunden werden.');

// Allgemeine Erstberichte mit redaktionellen Metaformulierungen oder erfundenen
// Folgeaufgaben dürfen die QS nicht passieren.
$generalQsNeedle = '$contentQs=gfEngelValidate($key,$result,$meta,$instructions);';
$generalQsReplacement = <<<'PHP_CODE'
if($key==='erstbericht'){$generalFaults=[];$generalText=gfEngelText($result);if(preg_match('/\b(?:vom\s+Bearbeiter|im\s+Arbeitsauftrag|im\s+System\s+(?:geführt|hinterlegt)|ist\s+(?:als\s+[^.]{0,80}\s+)?zu\s+übernehmen|anhand\s+der\s+Unterlagen\s+übernommen)\b/ui',$generalText))$generalFaults[]='interne Bearbeitungs- oder Herkunftssprache';if(preg_match('/\b(?:Gesamtansatz|Kostenschätzung)\b[^.]{0,100}\b0(?:,00)?\s*(?:EUR|€)/ui',$generalText)&&preg_match('/\b(?:Reserve|KVA|Kostenvoranschlag)\b[^.]{0,100}\b[1-9][0-9.]*,[0-9]{2}\s*(?:EUR|€)/ui',$generalText))$generalFaults[]='veralteter Nullansatz neben aktuellem Betrag';if(preg_match('/\bTerminabgleich\b|\bTermin\s+mit\s+[^.]{0,80}\b(?:offen|abzustimmen)\b/ui',$generalText)&&!preg_match('/\bTermin(?:abgleich)?\b[^.]{0,100}\b(?:offen|abstimmen|vereinbaren|erforderlich)\b/ui',$instructions))$generalFaults[]='nicht beauftragter zukünftiger Termin';if($generalFaults){$repair=$content;$repair[]=['type'=>'input_text','text'=>"VERBINDLICHE FEHLERKORREKTUR VOR DER AUSGABE: Der Entwurf enthält folgende unzulässige Punkte: ".implode(', ',$generalFaults).". Korrigiere den vollständigen Bericht und gib ausschließlich das verlangte JSON mit exakt denselben Überschriften aus. Schreibe nur fachliche Feststellungen und Bewertungen, niemals über Bearbeiter, Arbeitsauftrag, System, Quellenübernahme oder den Erstellungsprozess. Formuliere die Reserve neutral als fachliches Ergebnis. Die Reserve ist eine belastbare Gesamtschätzung der voraussichtlichen versicherten Schadenaufwendungen und darf niemals willkürlich unter bereits konkret belegten Kostenansätzen liegen. Vor Festsetzung der Reserve sind sämtliche vorhandenen Rechnungen, Kostenvoranschläge, Angebote, bereits ausgeführten aber noch nicht abgerechneten Leistungen sowie erkennbare offene Folgekosten zu berücksichtigen. Nicht überlappende bekannte Bruttobeträge sind zusammenzurechnen. Überlappen Rechnung und KVA denselben Leistungsumfang, darf nicht doppelt gerechnet werden; dann ist der noch offene beziehungsweise höhere Gesamtaufwand nachvollziehbar anzusetzen. Die Reserve darf jedenfalls nicht niedriger sein als der höchste einzelne belegte versicherte KVA-/Angebotsbetrag und bei mehreren voneinander unabhängigen Kostenblöcken nicht niedriger als deren Summe. Bereits ausgeführte, aber noch nicht abgerechnete Leistungen sind mit dem vorliegenden KVA oder einem sonst belastbaren Ansatz in der Reserve zu berücksichtigen. Ein Selbstbehalt wird bei der Schadenreserve nicht von den voraussichtlichen Bruttoschadenkosten abgezogen, sofern nicht ausdrücklich eine Entschädigungsreserve nach Selbstbehalt verlangt wird. Bestehen noch unbekannte Folgekosten, ist zusätzlich ein angemessener nachvollziehbarer Puffer zu berücksichtigen; keine pauschale Fantasiezahl und keine Reserve unter dem bekannten Kostenstand. Entferne veraltete Nullansätze und grobe Kostenschwellen, wenn aktuelle konkrete Beträge vorliegen. Erfinde aus genannten Personen keine Termine, Kontaktaufnahmen oder offenen Aufgaben. Erhalte alle belegten Teilnehmer, Feststellungen, Beträge und Aufmaße.\n\nFEHLERHAFTER ENTWURF:\n".json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];$result=gfEngelPrepare($key,gfOpenAI($repair,$system),$meta);$generalHeadings=gfHeadings($key);$generalSections=is_array($result['sections']??null)?array_values($result['sections']):[];if(count($generalSections)!==count($generalHeadings))throw new RuntimeException('Automatische Berichtskorrektur unvollständig.');foreach($generalHeadings as$generalIndex=>$generalHeading)$generalSections[$generalIndex]['heading']=$generalHeading;$result['sections']=$generalSections;$generalText=gfEngelText($result);if(preg_match('/\b(?:vom\s+Bearbeiter|im\s+Arbeitsauftrag|im\s+System\s+(?:geführt|hinterlegt)|ist\s+(?:als\s+[^.]{0,80}\s+)?zu\s+übernehmen|anhand\s+der\s+Unterlagen\s+übernommen)\b/ui',$generalText))throw new RuntimeException('Automatische Berichtskorrektur konnte interne Bearbeitungssprache nicht vollständig entfernen.');if(preg_match('/\bTerminabgleich\b|\bTermin\s+mit\s+[^.]{0,80}\b(?:offen|abzustimmen)\b/ui',$generalText)&&!preg_match('/\bTermin(?:abgleich)?\b[^.]{0,100}\b(?:offen|abstimmen|vereinbaren|erforderlich)\b/ui',$instructions))throw new RuntimeException('Automatische Berichtskorrektur konnte einen unbelegten Termin nicht entfernen.');}}
$contentQs=gfEngelValidate($key,$result,$meta,$instructions);
PHP_CODE;
$source = str_replace($generalQsNeedle, trim($generalQsReplacement), $source);
$generalFaultInit = "if(\$key==='erstbericht'){\$generalFaults=[];";
$generalFaultInitExtended = <<<'PHP_CODE'
if($key==='erstbericht'){$generalFaults=[];$generalHergang='';$generalUmfang='';$generalKalkulation='';foreach(($result['sections']??[])as$generalSection){$generalSectionHeading=gfNorm((string)($generalSection['heading']??''));if($generalSectionHeading==='schadenhergang')$generalHergang=(string)($generalSection['text']??'');if($generalSectionHeading==='schadenumfang')$generalUmfang=(string)($generalSection['text']??'');if($generalSectionHeading==='kalkulation')$generalKalkulation=(string)($generalSection['text']??'');}if(preg_match('/\b(?:Makler|Agentur|Vermittler)\b/ui',$instructions)&&!preg_match('/\b(?:Makler|Agentur|Vermittler)\b/ui',$generalHergang))$generalFaults[]='der im aktuellen Ortstermin genannte Makler beziehungsweise Vermittler fehlt im Schadenhergang und muss mit belegtem Namen und Funktion aufgenommen werden';if(preg_match('/\b(?:nach\s+den\s+vorliegenden|laut|gemäß|anhand|aus\s+dem|im\s+(?:Angebot|KVA)|belegt|übernommen)\b[^.]{0,100}\b(?:m²|qm|lfm)\b/ui',$generalUmfang))$generalFaults[]='das Aufmaß enthält einen unzulässigen Herkunftshinweis und muss als unmittelbare sachverständige Flächenfeststellung formuliert werden';if(preg_match('/\b(?:DIGITS?|Feuchtemesswerte?)\b/ui',$generalText))$generalFaults[]='DIGITS beziehungsweise einzelne Feuchtemesswerte dürfen im fertigen Bericht weder als Flächen noch als erläuternde Zahlenreihe erscheinen; ausschließlich die fachliche Feuchtebewertung ausgeben';if(preg_match('/\bHer\s+[A-ZÄÖÜ]/u',$generalText))$generalFaults[]='die Anrede Herr ist orthografisch falsch geschrieben';$generalHasKva=preg_match('/\b(?:KVA|Kostenvoranschlag|Angebot)\b/ui',implode(' ',array_map('strval',$sourceNames)))===1;if($generalHasKva&&(!preg_match('/\b(?:EUR|€)\b/u',$generalKalkulation)||preg_match('/\b(?:kein|keine|nicht)\b[^.]{0,80}\b(?:KVA|Kostenvoranschlag|Angebot)\b/ui',$generalKalkulation)))$generalFaults[]='der vorhandene Kostenvoranschlag beziehungsweise das Angebot wurde nicht vollständig mit Hauptpositionen sowie Netto-, Umsatzsteuer- und Bruttobetrag in der Kalkulation ausgewertet';$generalReserveAmount=null;if(preg_match('/\b(?:Schadenreserve|Reserve)\b[^0-9]{0,80}([0-9]{1,3}(?:\.[0-9]{3})*(?:,[0-9]{2})|[0-9]+(?:,[0-9]{2})?)\s*(?:EUR|€)/ui',$generalKalkulation,$generalReserveMatch))$generalReserveAmount=(float)str_replace(['. ','.',','],['','', '.'],$generalReserveMatch[1]);$generalKnownGross=[];if(preg_match_all('/\b(?:Rechnung|KVA|Kostenvoranschlag|Angebot)\b[^.]{0,180}?([0-9]{1,3}(?:\.[0-9]{3})*(?:,[0-9]{2})|[0-9]+(?:,[0-9]{2})?)\s*(?:EUR|€)\s*(?:brutto)?/ui',$generalKalkulation,$generalCostMatches))foreach(($generalCostMatches[1]??[])as$generalCostValue){$generalCost=(float)str_replace(['. ','.',','],['','', '.'],(string)$generalCostValue);if($generalCost>0)$generalKnownGross[]=$generalCost;}if($generalReserveAmount!==null&&$generalKnownGross&&$generalReserveAmount+0.01<max($generalKnownGross))$generalFaults[]='die Schadenreserve liegt unter einem bereits konkret belegten KVA-/Angebots- oder Rechnungsbetrag; Reserve mindestens auf den bekannten versicherten Kostenstand anheben und voneinander unabhängige Kostenblöcke zusätzlich zusammenführen';
PHP_CODE;
$source = str_replace($generalFaultInit, trim($generalFaultInitExtended), $source, $countGeneralFaultExtension);
if ($countGeneralFaultExtension !== 1) throw new RuntimeException('Teilnehmer- und Aufmaß-QS konnte nicht sicher angebunden werden.');
$generalContentQsNeedle = '$contentQs=gfEngelValidate($key,$result,$meta,$instructions);';
$generalContentQsExtended = <<<'PHP_CODE'
if(in_array($key,['erstbericht','erstbericht_sv_gf'],true)){$sanitizeReportText=static function(string$text):string{$text=preg_replace('/\bHer(?=\s+[A-ZÄÖÜ])/u','Herr',$text)??$text;$text=preg_replace('/(?:^|(?<=[.!?]))\s*[^.!?\n]*(?:\bDIGITS?\b|(?:\b\d{1,3},\d{1,2}\b[^.!?\n]*){3,})[^.!?\n]*(?:[.!?]+|$)/ui',' ',$text)??$text;return trim(preg_replace('/\s{2,}/u',' ',$text)??$text);};$result['summary']=$sanitizeReportText((string)($result['summary']??''));foreach(($result['sections']??[])as$checkedIndex=>$checkedSection)$result['sections'][$checkedIndex]['text']=$sanitizeReportText((string)($checkedSection['text']??''));if(is_array($result['open_points']??null)){$result['open_points']=array_values(array_filter(array_map($sanitizeReportText,$result['open_points']),static fn($v)=>$v!==''));}$checkedHasKva=preg_match('/\b(?:KVA|Kostenvoranschlag|Angebot)\b/ui',implode(' ',array_map('strval',$sourceNames)))===1;$checkedCostText='';foreach(($result['sections']??[])as$checkedSection){$checkedHeading=gfNorm((string)($checkedSection['heading']??''));if(in_array($checkedHeading,['kalkulation','schadenabwicklung'],true))$checkedCostText.=' '.(string)($checkedSection['text']??'');}$kvaAmountCount=preg_match_all('/\b\d+(?:\.\d{3})*,\d{2}\s*(?:EUR|€)/u',$checkedCostText);$kvaMissing=$checkedHasKva&&($kvaAmountCount<4||preg_match('/\b(?:kein|keine|nicht)\b[^.]{0,80}\b(?:KVA|Kostenvoranschlag|Angebot)\b/ui',$checkedCostText));if($kvaMissing){$kvaRepair=$content;$kvaRepair[]=['type'=>'input_text','text'=>"VERBINDLICHE KVA-KORREKTUR: Der Entwurf berücksichtigt den vorhandenen Original-KVA nicht vollständig. Überarbeite den gesamten Bericht und gib ausschließlich das verlangte JSON mit exakt den vorgegebenen Überschriften aus. Im Abschnitt Kalkulation beziehungsweise Schadenabwicklung müssen Aussteller, Angebotsnummer und Datum, sämtliche wesentlichen angebotenen Leistungsgruppen jeweils mit EUR-Betrag sowie Netto-, Umsatzsteuer- und Bruttosumme stehen. Ohne ausdrücklich vorgegebene eigene Kürzung ist der vollständige Original-KVA der maßgebliche Ansatz. Leistungen des KVA müssen außerdem sachgerecht in Schadenumfang und Maßnahmen einfließen. Keine DIGITS, keine einzelnen Feuchtemesszahlen und keine Erläuterung, dass solche Werte keine Flächen seien. Belastbare echte Flächen mit Einheit vollständig übernehmen. Keine Tatsachen oder Beträge erfinden.\n\nZU KORRIGIERENDER ENTWURF:\n".json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];$result=gfEngelPrepare($key,gfOpenAI($kvaRepair,$system),$meta);$headings=gfHeadings($key);$sections=is_array($result['sections']??null)?array_values($result['sections']):[];if(count($sections)!==count($headings))throw new RuntimeException('KVA-Korrektur unvollständig.');foreach($headings as$sectionIndex=>$heading){$sections[$sectionIndex]['heading']=$heading;$sections[$sectionIndex]['text']=$sanitizeReportText((string)($sections[$sectionIndex]['text']??''));}$result['sections']=$sections;$result['summary']=$sanitizeReportText((string)($result['summary']??''));}$checkedFullText=gfEngelText($result);if(preg_match('/\bDIGITS?\b/ui',$checkedFullText)||preg_match('/(?:\b\d{1,3},\d{1,2}\b[^.!?\n]*){3,}/u',$checkedFullText))throw new RuntimeException('Erstbericht gesperrt: DIGITS oder einzelne Feuchtemesswerte sind noch enthalten.');$checkedCostText='';foreach(($result['sections']??[])as$checkedSection){$checkedHeading=gfNorm((string)($checkedSection['heading']??''));if(in_array($checkedHeading,['kalkulation','schadenabwicklung'],true))$checkedCostText.=' '.(string)($checkedSection['text']??'');}$kvaAmountCount=preg_match_all('/\b\d+(?:\.\d{3})*,\d{2}\s*(?:EUR|€)/u',$checkedCostText);if($checkedHasKva&&$kvaAmountCount<3)error_log('[gf-ai] KVA-QS: weniger als drei Geldsummen trotz automatischer Korrektur; Dokument wird zur manuellen Prüfung ausgegeben.');}
$contentQs=gfEngelValidate($key,$result,$meta,$instructions);
PHP_CODE;
$source = str_replace($generalContentQsNeedle, trim($generalContentQsExtended), $source, $countGeneralFinalQs);
if ($countGeneralFinalQs !== 1) throw new RuntimeException('Abschließende Teilnehmer- und Aufmaß-QS konnte nicht sicher angebunden werden.');

// Für den einfachen Portalablauf den fachlich erzeugten Text zuerst als
// bearbeitbaren Entwurf zurückgeben. Erst nach ausdrücklichem Klick wird daraus
// das eigentliche Word-/Excel-Dokument erzeugt.
function gfPortalDraftText(string $title, array $result): string
{
    $parts = [];
    $summary = trim((string)($result['summary'] ?? ''));
    if ($summary !== '') $parts[] = $summary;
    foreach (($result['sections'] ?? []) as $section) {
        $heading = trim((string)($section['heading'] ?? ''));
        $text = trim((string)($section['text'] ?? ''));
        if ($text === '') continue;
        $parts[] = ($heading !== '' && gfNorm($heading) !== gfNorm($title) ? $heading."\n" : '').$text;
    }
    if (!empty($result['open_points']) && is_array($result['open_points'])) {
        $points = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $result['open_points'])));
        if ($points) $parts[] = "Offene Punkte\n- ".implode("\n- ", $points);
    }
    return trim(implode("\n\n", $parts));
}

$source = str_replace('$created=[];$staged=[];', '$created=[];$staged=[];$drafts=[];', $source, $countDraftInit);
if ($countDraftInit !== 1) throw new RuntimeException('Entwurfsrückgabe konnte nicht initialisiert werden.');
$draftNeedle = '$contentQs=gfEngelValidate($key,$result,$meta,$instructions);if(gfExcelOutput($key))';
$draftReplacement = <<<'PHP_CODE'
$contentQs=gfEngelValidate($key,$result,$meta,$instructions);if($key==='kalkulation'){$calculationState=gfCalculationDraftState($result,$meta,$instructions);$calculationDraftKey='ai:'.$jobId;gfSaveCalculationDraft($calculationDraftKey,$folderId,$meta,$calculationState,gfCalculationJobOwner($jobId));$created[]=['id'=>$calculationDraftKey,'name'=>'Kalkulation – KI-Erstentwurf bearbeiten','webViewLink'=>gfCalculationDraftLink($calculationDraftKey),'webContentLink'=>null,'type'=>'kalkulation','continued'=>false,'template_used'=>'Kalkulationsseite','qs'=>['content'=>$contentQs,'document'=>['passed'=>true,'checks'=>['editable_draft_saved']]]];gfJobUpdate($jobId,'running',min(94,$base+(int)floor(70/$total)),'Kalkulationsentwurf wurde auf der Kalkulationsseite gespeichert.');continue;}$drafts[]=['type'=>$key,'title'=>$title,'content'=>gfPortalDraftText($title,$result)];if(!empty($order['draft_only'])){gfJobUpdate($jobId,'running',min(94,$base+(int)floor(70/$total)),$title.' wurde als bearbeitbarer Entwurf erstellt.');continue;}if(gfExcelOutput($key))
PHP_CODE;
$draftReplacement = trim($draftReplacement);
$source = str_replace($draftNeedle, $draftReplacement, $source, $countDraftResult);
if ($countDraftResult !== 1) throw new RuntimeException('Entwurfsrückgabe konnte nicht an die Dokumenterstellung angebunden werden.');
$finalNeedle = '$result=[\'ok\'=>true,\'created\'=>$created,\'count\'=>count($created),';
$finalReplacement = '$result=[\'ok\'=>true,\'created\'=>$created,\'drafts\'=>$drafts,\'performance\'=>[\'evidence\'=>$evidencePerformance],\'count\'=>count($created),';
$source = str_replace($finalNeedle, $finalReplacement, $source, $countDraftResponse);
if ($countDraftResponse !== 1) throw new RuntimeException('Entwurf konnte nicht in die Antwort übernommen werden.');
$source = str_replace("gfJobUpdate(\$jobId,'done',100,'Dokumentpaket vollständig erstellt.',\$result);", "gfJobUpdate(\$jobId,'done',100,!empty(\$order['draft_only'])?'Entwurf vollständig erstellt.':'Dokumentpaket vollständig erstellt.',\$result);", $source);
$source = str_replace(
    "error_log('[gf-ai-job '.\$jobId.'] '.\$e->getMessage());gfJobUpdate(\$jobId,'failed',100,'Verarbeitung fehlgeschlagen.',null,\$e->getMessage());",
    "\$jobFrames=array_slice(\$e->getTrace(),0,4);\$jobTrace=implode(' > ',array_map(static fn(\$frame)=>(string)(\$frame['function']??'?').'@'.(int)(\$frame['line']??0),\$jobFrames));\$jobError=\$e->getMessage().' (Laufzeitzeile '.\$e->getLine().')'.(\$jobTrace!==''?' ['.\$jobTrace.']':'');error_log('[gf-ai-job '.\$jobId.'] '.\$jobError);gfJobUpdate(\$jobId,'failed',100,'Verarbeitung fehlgeschlagen.',null,\$jobError);",
    $source,
    $jobErrorDetailCount
);
if ($jobErrorDetailCount !== 1) throw new RuntimeException('Fehlerdiagnose konnte nicht sicher angebunden werden.');

// IONOS beendet lange PHP-Webanfragen. Im CLI-Modus wird deshalb genau ein
// bereits angelegter Auftrag unabhängig vom Browser bis zum Ende verarbeitet.
$cliJobId = (int)(getenv('GF_AI_JOB_ID') ?: 0);
$cliWorkerNext = getenv('GF_AI_WORKER_NEXT') === '1';
if (PHP_SAPI === 'cli' && ($cliJobId > 0 || $cliWorkerNext)) {
    $httpPrelude = <<<'PHP_CODE'
commonHeaders();
$user=requireAuth();
if(!in_array($user['role']??'', ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) apiError(403,'Keine Berechtigung.');
if($_SERVER['REQUEST_METHOD']!=='POST') apiError(405,'POST erforderlich.');
PHP_CODE;
    $source = str_replace(["\r\n", "\r"], "\n", $source);
    $httpPrelude = str_replace(["\r\n", "\r"], "\n", $httpPrelude);
    $source = str_replace($httpPrelude, '$user=[\'email\'=>\'server-worker\',\'role\'=>\'administrator\'];', $source, $cliPreludeCount);
    if ($cliPreludeCount !== 1) throw new RuntimeException('CLI-Worker konnte nicht sicher initialisiert werden.');
    $dispatcherPosition = strrpos($source, "\ntry{\n    \$body=requestBody();");
    if ($dispatcherPosition === false) throw new RuntimeException('CLI-Worker konnte den HTTP-Dispatcher nicht abtrennen.');
    $source = substr($source, 0, $dispatcherPosition) . <<<'PHP_CODE'

$cliJobId=(int)(getenv('GF_AI_JOB_ID')?:0);
$cliLock=(int)db()->query("SELECT GET_LOCK('svnet_gf_ai_worker',0)")->fetchColumn();
if($cliLock!==1)exit(0);
if($cliJobId>0){$cliStmt=db()->prepare('SELECT id,payload_json,status FROM gf_ai_jobs WHERE id=:id LIMIT 1');$cliStmt->execute([':id'=>$cliJobId]);}
else{$cliStmt=db()->query("SELECT id,payload_json,status FROM gf_ai_jobs WHERE status='dispatching' ORDER BY id ASC LIMIT 1");}
while($cliRow=$cliStmt->fetch(PDO::FETCH_ASSOC)){
    $cliJobId=(int)($cliRow['id']??$cliJobId);
    $cliStatus=(string)($cliRow['status']??'');
    if(!in_array($cliStatus,['queued','dispatching','running'],true))break;
    $cliPayload=json_decode((string)($cliRow['payload_json']??''),true);
    if(!is_array($cliPayload))throw new RuntimeException('KI-Auftrag ist unvollständig.');
    gfRunJob($cliJobId,$cliPayload);
    if((int)(getenv('GF_AI_JOB_ID')?:0)>0)break;
    $cliStmt=db()->query("SELECT id,payload_json,status FROM gf_ai_jobs WHERE status='dispatching' ORDER BY id ASC LIMIT 1");
}
db()->query("SELECT RELEASE_LOCK('svnet_gf_ai_worker')");
exit(0);
PHP_CODE;
}

// Erst nach allen Laufzeit-Erweiterungen normalisieren, damit auch die spaeter
// eingesetzten fachlichen Pruefregeln null-sicher arbeiten.
$source = str_replace('preg_match(', 'gfSafePregMatch(', $source);

// Kein harter Abbruch mehr bei bereits im Kern enthaltenen oder leicht geänderten Mustern.
if (getenv('GF_AI_VALIDATE_ONLY') === '1') {
    $target = getenv('GF_AI_VALIDATE_TARGET') ?: sys_get_temp_dir().'/gf-ai-runtime.php';
    if (file_put_contents($target, "<?php\n".$source) === false) {
        throw new RuntimeException('Zusammengesetzter KI-Laufzeitcode konnte nicht zur Prüfung geschrieben werden.');
    }
    echo $target."\n";
    exit;
}
try {
    eval($source);
} catch (Throwable $error) {
    $gfFatalHandled = true;
    $errorId = bin2hex(random_bytes(4));
    $detail = $error->getMessage().' in Laufzeitzeile '.$error->getLine();
    error_log('[gf-ai-bootstrap '.$errorId.'] '.$detail);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['error'=>'Entwurf konnte nicht gestartet werden (Fehler-ID '.$errorId.'): '.$detail], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
