<?php
declare(strict_types=1);

$core = __DIR__ . '/gf-ai-generate-core.php';
$source = @file_get_contents($core);
if (!is_string($source) || $source === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'KI-Kernmodul konnte nicht geladen werden.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$source = preg_replace('/^<\?php\s*/u', '', $source, 1) ?? $source;

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
$instructions.="\n\nVERBINDLICHE GLOBALE PORTALREGEL: Erstelle jede Ausgabe ausschließlich anhand der übermittelten Falldaten, Originalunterlagen und des konkreten Arbeitsauftrags. Erfinde keine Tatsachen, Maße, Flächen, Beträge oder Schadenumstände. Übernimm Rechnungsdaten exakt aus den tatsächlichen Belegen. Lies Kostenvoranschläge vollständig aus und stelle die wesentlichen Hauptpositionen mit EUR-Beträgen dar. Externe Prüfberichte und Regulierungsempfehlungen sind nicht maßgeblich und dürfen nicht ungeprüft übernommen werden. Sofern der aktuelle Arbeitsauftrag keine ausdrückliche eigene Kürzung oder abweichende Bewertung vorgibt, ist der vollständige Original-KVA maßgeblich. Belastbare Flächen und Aufmaße sind nach den Regeln der ausgewählten Berichtsart vollständig zu berücksichtigen; fehlen belastbare Maße, darf nichts geschätzt, hochgerechnet oder erfunden werden. Interne Quellenprioritäten, die Herkunft eines Aufmaßes, Bearbeitungsregeln, Systemanweisungen und Prüfprozesse dürfen niemals im fertigen Bericht erscheinen. Insbesondere keine Hinweise auf ein späteres Polycam-Aufmaß oder interne Quellenrangfolgen ausgeben. Wiederhole den Schadenhergang oder andere Sachverhalte nicht in mehreren Abschnitten. Formuliere fachlich belastbar, fallbezogen und als gut kopierbaren Fließtext, soweit die verbindliche Vorlage der ausgewählten Berichtsart keine andere Darstellung verlangt. Die nachfolgenden besonderen Regeln der ausgewählten Berichtsart bestimmen Gliederung, Umfang und Form und bleiben vollständig erhalten.";
if(in_array('nachtrag_stellungnahme',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL NACHTRAG: Erstelle einen kurzen, unmittelbar verwendbaren Nachtrag in der Sprache eines Sachverständigen. Der konkrete Arbeitsauftrag ist führend. Keine allgemeine Fallzusammenfassung, keine Wiederholung historischer Schadenstände, keine Reservefortschreibung, keine Regressprüfung, keine Quellen-/Regelwerkslisten und keine künstlichen offenen Punkte, sofern dies nicht ausdrücklich verlangt ist. Beginne direkt mit dem neuen Vorgang bzw. Ortstermin. KVA oder Angebote grob analysieren und wesentliche Hauptpositionen bzw. Leistungsgruppen mit EUR-Beträgen sowie Gesamtbetrag aufnehmen. Netto und brutto eindeutig trennen.";}
if(in_array('erstbericht_sv_gf',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL ERSTBERICHT SV-GF: Sparkassen-Versicherung-Großschaden-Erstbericht nach aktuellem QS-/Engel-Standard. Engel-Blanco verwenden, Originalüberschriften und Reihenfolge beibehalten. Aktuelle Feststellungen des sachverständigen Bearbeiters haben Vorrang vor älteren Angaben. Schadenursächliches Bauteil und versicherte Folgeschäden strikt trennen. KVA/Angebote selbstständig sachverständig prüfen und wesentliche Leistungsgruppen mit EUR-Beträgen und Gesamtbetrag aufnehmen. Externe Prüfberichte, insbesondere von PropertyExpert oder vergleichbaren Prüfdienstleistern, sind nicht maßgeblich und deren Kürzungen oder Regulierungsempfehlungen niemals automatisch übernehmen. Sofern der aktuelle Arbeitsauftrag keine ausdrückliche abweichende Bewertung oder Kürzung vorgibt, gilt der vollständige Original-KVA als Bewertungs- und Freigabebetrag. Diese Regel gilt auch bei der Sparkassen Versicherung. Belegidentität ausschließlich aus Originalbelegen bestimmen. Rechnungen einzeln mit Aussteller, Rechnungsnummer, Datum und Betrag aufnehmen, soweit geprüft oder belegt. Reserve ausschließlich im Abschnitt Reserve EUR und dort nur als Zahl bzw. spartengetrennte Zahlen. Regress belastbar behandeln. Nichts erfinden.";}
if(in_array('erstbericht',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL ALLGEMEINER ERSTBERICHT: Erstelle einen unmittelbar versendbaren sachverständigen Erstbericht aus vollständiger Fallakte und Arbeitsauftrag. Schreibe unter den vorgegebenen fachlichen Überschriften ausschließlich ausformulierte, gut kopierbare Fließtextabsätze; kein Formular, keine Stichpunktmaske und keine Tabellen. Der aktuelle Ortstermin und ausdrückliche Feststellungen des Bearbeiters sind maßgeblicher aktueller Sachstand. Der normale Schadenbericht ist vollständig abzuarbeiten: Risiko; Schaden mit Schadenhergang, Schadenursache und Schadenumfang; Ersatzpflicht; Regress; Obliegenheitsverletzungen; Doppelversicherung; Polizei bei ED/Feuer; Handlungsempfehlungen; Kalkulation. JEDEN SACHVERHALT NUR EINMAL DARSTELLEN: Risiko enthält ausschließlich Vertrag, Versicherungsobjekt und Risikomerkmale; Schaden enthält einmalig Hergang, Ursache, Schadenbild, Umfang und Aufmaß; Ersatzpflicht enthält nur Deckungsbewertung und Ergebnis ohne erneute Schilderung; Regress, Obliegenheiten, Doppelversicherung und Polizei enthalten nur das jeweilige Prüfergebnis; Handlungsempfehlungen enthalten nur Maßnahmen; Kalkulation enthält ausschließlich Belegprüfung, Beträge, Freigabe und Reserve. Keine Wiederholung des Schadenhergangs, der Ursache, der betroffenen Bereiche oder der Maßnahmen in mehreren Kapiteln. Nicht einschlägige Punkte ausdrücklich mit Nein bzw. nicht einschlägig und kurzer Begründung abschließen, nicht kommentarlos leer lassen. Schadenursache und Folgeschaden sauber trennen. KVA/Angebote auslesen, selbstständig sachverständig prüfen und Hauptleistungsgruppen mit EUR-Beträgen sowie Netto-, Umsatzsteuer- und Bruttosumme aufnehmen. Externe Prüfberichte, insbesondere von PropertyExpert oder vergleichbaren Prüfdienstleistern, sind nicht maßgeblich und deren Kürzungen oder Regulierungsempfehlungen niemals automatisch übernehmen. Sofern der aktuelle Arbeitsauftrag keine ausdrückliche abweichende Bewertung oder Kürzung vorgibt, gilt der vollständige Original-KVA als Bewertungs- und Freigabebetrag. Belegidentität ausschließlich aus Originalbelegen bestimmen. Rechnungen einzeln mit Aussteller, Rechnungsnummer, Datum und Betrag aufnehmen, soweit geprüft oder belegt. AUFMASS IM BERICHT QUELLENNEUTRAL FORMULIEREN: Maße und Flächen als sachverständige Feststellung mit Rechenansatz ausgeben. Im Aufmaßabsatz niemals Firmen-, Dokument-, Angebots-, KVA-, Gutachten-, Prüfbericht- oder Produktnamen und niemals Formulierungen wie laut, gemäß, anhand, aus dem Angebot, im Angebot ausgewiesen, belegt oder übernommen verwenden. Die interne Herkunft und Quellenpriorität darf nicht erkennbar sein. Reserve und Selbstbehalt strikt trennen: Für die Reserve hat der aktuelle Regulierungsauftrag mit dem Feld Gesamtreserve aktuell beziehungsweise Erstreserve Vorrang vor sonstigen Metadaten und älteren Unterlagen. Selbstbehalt, Forderung, KVA-, Rechnungs- oder Entschädigungsbetrag niemals als Reserve interpretieren. Ist keine Reserve ausdrücklich belegt, keine Reserve ausgeben. Nichts erfinden.";}
$hasErstbericht=false;foreach($outputs as$outputKey){$outputKey=(string)$outputKey;if(strpos($outputKey,'erstbericht')!==false||in_array($outputKey,['schadenbericht','sv_erstbericht','erstbericht_sv'],true)){$hasErstbericht=true;break;}}
if($hasErstbericht){$instructions.="\n\nVERBINDLICHE QS-REGEL FLÄCHENBERECHNUNG FÜR ALLE ERSTBERICHTE: In jedem Erstbericht ist eine Flächen-/Aufmaßdarstellung aufzunehmen, sofern belastbare Maße oder Flächen vorhanden sind. Priorität 1: eigene Aufmaße des Sachverständigen, insbesondere Polycam. Priorität 2: ausdrücklich ausgewiesene Maße und Flächen aus technischen Unterlagen wie Leckortungsbericht, Trocknungsbericht, Handwerker-Erstbericht, KVA, Angebot oder Aufmaß. Werte als eigene sachverständige Feststellung ohne Herkunftshinweis übernehmen. Boden-, Wand- und Deckenflächen, Raumumfänge, Längen und sonstige relevante Aufmaßwerte nachvollziehbar darstellen. Bei belastbar angegebenen Längen/Breiten darf die Fläche rechnerisch ermittelt werden. Bei Widersprüchen gilt die höhere Quellenpriorität; keine Mittelwerte. Fehlen belastbare Maße, nichts schätzen oder erfinden. Diese Regel gilt für normalen Schaden-Erstbericht, SV-Erstbericht und SV-GF-Erstbericht nach Engel. WICHTIG: Die interne Quellenpriorität und insbesondere Hinweise auf Polycam oder spätere Aufmaße dürfen niemals im Berichtstext erscheinen.";}
PHP_CODE;
if (!str_contains($source, 'VERBINDLICHE PORTALREGEL ALLGEMEINER ERSTBERICHT')) {
    $source = str_replace($needle, $rules, $source, $countInstructions);
    if ($countInstructions !== 1) {
        throw new RuntimeException('Portalregeln konnten nicht sicher an den Arbeitsauftrag angebunden werden.');
    }
}

// Zwei getrennte Erstbericht-Typen sicherstellen, sofern der Kern sie noch nicht enthält.
if (!str_contains($source, "'erstbericht_sv_gf'=>'Erstbericht SV-GF (QS Engel)'")) {
    $source = str_replace("'erstbericht'=>'Erstbericht'", "'erstbericht'=>'Allgemeiner Erstbericht','erstbericht_sv_gf'=>'Erstbericht SV-GF (QS Engel)'", $source);
}
if (!str_contains($source, "'erstbericht_sv_gf'=>['Versicherungsverhältnisse'")) {
    $source = str_replace(
        "'erstbericht'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress']",
        "'erstbericht'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress'],'erstbericht_sv_gf'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress']",
        $source
    );
}
$source = str_replace(
    "'erstbericht'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress']",
    "'erstbericht'=>['Risiko','Schaden','Ersatzpflicht','Regress','Obliegenheitsverletzungen','Doppelversicherung','Polizeiliche Ermittlungen','Handlungsempfehlungen','Kalkulation']",
    $source
);
$source = str_replace(
    '$allowed=[\'dokumentenindex\',\'rechnungsregister\',\'erstbericht\',\'schadenprotokoll\'',
    '$allowed=[\'dokumentenindex\',\'rechnungsregister\',\'erstbericht\',\'erstbericht_sv_gf\',\'schadenprotokoll\'',
    $source
);

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
$caseFilesNeedle = '$caseFiles=gfCaseFiles($folderId);if(!$caseFiles)throw new RuntimeException(\'Im aktiven Fall wurden keine auswertbaren Unterlagen gefunden.\');';
$caseFilesReplacement = $caseFilesNeedle."\n\$portalReserve=gfPortalCurrentReserve(\$caseFiles);if(\$portalReserve!==null)\$meta['reserve']=\$portalReserve;";
if (!str_contains($source, 'gfPortalCurrentReserve($caseFiles)')) {
    $source = str_replace($caseFilesNeedle, $caseFilesReplacement, $source, $countReserveBinding);
    if ($countReserveBinding !== 1) throw new RuntimeException('Aktuelle Gesamtreserve konnte nicht sicher angebunden werden.');
}

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
$result=gfEngelPrepare($key,gfOpenAI($content,$system),$meta);if($key==='erstbericht'){$generalReview=$content;$generalReview[]=['type'=>'input_text','text'=>"REDAKTIONELLE SCHLUSS-QS: Überarbeite den folgenden Entwurf vollständig und gib wieder ausschließlich das verlangte JSON aus. Entferne ausnahmslos Wiederholungen zwischen den Kapiteln. Jeder Sachverhalt darf nur in seinem zuständigen Kapitel stehen. Das Aufmaß ist als sachverständige Feststellung mit Rechenansätzen darzustellen, jedoch vollständig quellenneutral: Im Aufmaßabsatz keine Firmen-, Dokument-, Angebots-, KVA-, Gutachten-, Prüfbericht- oder Produktnamen und keine Herkunftsformulierungen. Firmen- und Belegnamen sind nur im Kapitel Kalkulation zulässig. Externe Prüfberichte und deren Regulierungsempfehlungen sind nicht maßgeblich. Ohne ausdrückliche abweichende Vorgabe im aktuellen Arbeitsauftrag ist ausschließlich der vollständige Original-KVA als Bewertungs- und Freigabebetrag anzusetzen. Kürzungen aus PropertyExpert oder anderen Fremdprüfungen nicht übernehmen und nicht als eigenen Prüfstand darstellen. Reserve ausschließlich aus dem aktuellen Regulierungsauftrag, Feld Gesamtreserve aktuell beziehungsweise Erstreserve, übernehmen; niemals Selbstbehalt oder KVA als Reserve.\n\nZU ÜBERARBEITENDER ENTWURF:\n".json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];$result=gfEngelPrepare($key,gfOpenAI($generalReview,$system),$meta);$generalHeadings=gfHeadings($key);$generalSections=is_array($result['sections']??null)?array_values($result['sections']):[];if(count($generalSections)!==count($generalHeadings))throw new RuntimeException('Allgemeiner Erstbericht unvollständig: Erwartet werden '.count($generalHeadings).' fachliche Fließtextabschnitte.');foreach($generalHeadings as$generalIndex=>$generalHeading){$generalSections[$generalIndex]['heading']=$generalHeading;$generalSections[$generalIndex]['text']=str_ireplace(['schaden ursächlich','schaden ursächliche','schaden ursächlichen'],['schadenursächlich','schadenursächliche','schadenursächlichen'],(string)($generalSections[$generalIndex]['text']??''));}$result['sections']=$generalSections;}$contentQs=gfEngelValidate($key,$result,$meta,$instructions);
PHP_CODE;
if (!str_contains($source, 'Allgemeiner Erstbericht unvollständig:')) {
    $source = str_replace($resultNeedle, trim($resultReplacement), $source, $countGeneralPrepare);
    if ($countGeneralPrepare !== 1) {
        throw new RuntimeException('Allgemeiner Erstbericht konnte nicht sicher an die QS angebunden werden.');
    }
}

// Nachtragsausgabe ohne automatisch angehängte Quellen-/Regelwerkslisten.
if (!str_contains($source, "<title>Nachtrag</title>")) {
    $sig = 'function gfDocumentHtml(string $title,array $meta,array $result,array $sources,array $rules,string $userName):string{';
    $rep = <<<'PHP_CODE'
function gfDocumentHtml(string $title,array $meta,array $result,array $sources,array $rules,string $userName):string{if($title==='Allgemeiner Erstbericht'){$text='';foreach(($result['sections']??[])as$s){$h=trim((string)($s['heading']??''));$t=trim((string)($s['text']??''));if($h!==''&&$t!=='')$text.='<h2>'.gfH($h).'</h2><p>'.nl2br(gfH($t)).'</p>';}$caseNo=gfH((string)($meta['schaden_nr']??''));$policy=gfH((string)($meta['versicherungsschein_nr']??''));$object=gfH((string)($meta['vn_objekt']??$meta['versicherungsnehmer']??''));$damageStreet=trim((string)($meta['schaden_strasse']??''));$damageZip=trim((string)($meta['schaden_plz']??''));$damageCity=trim((string)($meta['schaden_ort']??''));$place=gfH(trim(($damageStreet!==''?$damageStreet:(string)($meta['strasse']??'')).' '.($damageZip!==''?$damageZip:(string)($meta['plz']??'')).' '.($damageCity!==''?$damageCity:(string)($meta['ort']??''))));return '<!doctype html><html><head><meta charset="utf-8"><title>Allgemeiner Erstbericht</title><style>body{font-family:Arial,sans-serif;font-size:11pt;line-height:1.5;margin:2cm;color:#111}h1{font-size:17pt;margin:0 0 18px}h2{font-size:11pt;text-decoration:underline;margin:18px 0 7px}p{margin:0 0 11px}.meta{margin-bottom:18px}</style></head><body><h1>Allgemeiner Erstbericht</h1><p class="meta"><strong>Schaden-Nr.:</strong> '.$caseNo.($policy!==''?'<br><strong>Versicherungsschein-Nr.:</strong> '.$policy:'').($object!==''?'<br><strong>Versicherungsnehmer / Objekt:</strong> '.$object:'').($place!==''?'<br><strong>Schadenort:</strong> '.$place:'').'</p>'.$text.'<p>Christian Wächter<br>Sachverständiger &amp; Großschadenregulierer<br>DIN EN ISO/IEC 17024 zertifiziert<br>https://www.sv-netzwerk.eu/</p></body></html>';}if($title==='Nachtrag / Stellungnahme'){$text='';foreach(($result['sections']??[])as$s){$t=trim((string)($s['text']??''));if($t!=='')$text.='<p>'.nl2br(gfH($t)).'</p>';}$caseNo=gfH((string)($meta['schaden_nr']??''));$object=gfH((string)($meta['vn_objekt']??$meta['versicherungsnehmer']??''));return '<!doctype html><html><head><meta charset="utf-8"><title>Nachtrag</title><style>body{font-family:Arial,sans-serif;font-size:11pt;line-height:1.5;margin:2cm;color:#111}h1{font-size:16pt;margin:0 0 18px}p{margin:0 0 12px}.meta{margin-bottom:18px}</style></head><body><h1>Nachtrag</h1><p class="meta"><strong>Schaden-Nr.:</strong> '.$caseNo.($object!==''?'<br><strong>VN / Objekt:</strong> '.$object:'').'</p>'.$text.'<p>Mit freundlichen Grüßen<br><br>Christian Wächter<br>Sachverständiger &amp; Großschadenregulierer<br>DIN EN ISO/IEC 17024 zertifiziert<br>https://www.sv-netzwerk.eu/</p></body></html>';}
PHP_CODE;
    $source = str_replace($sig, $rep, $source);
}

// Widersprüche und Regress nur fachlich relevant ausgeben.
$source = str_replace('Widersprüche ausdrücklich benennen.','Widersprüche nur benennen, wenn sie für den konkreten Arbeitsauftrag oder die Entscheidung relevant sind.',$source);
$source = str_replace('Bei Berichten Regressaussage nicht vergessen.','Regressaussagen nur in Erst-, Zwischen- und Schlussberichten oder bei ausdrücklichem Arbeitsauftrag aufnehmen.',$source);

// Kein harter Abbruch mehr bei bereits im Kern enthaltenen oder leicht geänderten Mustern.
eval($source);
