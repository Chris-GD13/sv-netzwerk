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
if(in_array('nachtrag_stellungnahme',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL NACHTRAG: Erstelle einen kurzen, unmittelbar verwendbaren Nachtrag in der Sprache eines Sachverständigen. Der konkrete Arbeitsauftrag ist führend. Keine allgemeine Fallzusammenfassung, keine Wiederholung historischer Schadenstände, keine Reservefortschreibung, keine Regressprüfung, keine Quellen-/Regelwerkslisten und keine künstlichen offenen Punkte, sofern dies nicht ausdrücklich verlangt ist. Beginne direkt mit dem neuen Vorgang bzw. Ortstermin. KVA oder Angebote grob analysieren und wesentliche Hauptpositionen bzw. Leistungsgruppen mit EUR-Beträgen sowie Gesamtbetrag aufnehmen. Netto und brutto eindeutig trennen.";}
if(in_array('erstbericht_sv_gf',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL ERSTBERICHT SV-GF: Sparkassen-Versicherung-Großschaden-Erstbericht nach aktuellem QS-/Engel-Standard. Engel-Blanco verwenden, Originalüberschriften und Reihenfolge beibehalten. Aktuelle Feststellungen des sachverständigen Bearbeiters haben Vorrang vor älteren Angaben. Schadenursächliches Bauteil und versicherte Folgeschäden strikt trennen. KVA/Angebote grob prüfen und wesentliche Leistungsgruppen mit EUR-Beträgen und Gesamtbetrag aufnehmen. Belegidentität ausschließlich aus Originalbelegen bestimmen. Rechnungen einzeln mit Aussteller, Rechnungsnummer, Datum und Betrag aufnehmen, soweit geprüft oder belegt. Reserve ausschließlich im Abschnitt Reserve EUR und dort nur als Zahl bzw. spartengetrennte Zahlen. Regress belastbar behandeln. Nichts erfinden.";}
if(in_array('erstbericht',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL ALLGEMEINER ERSTBERICHT: Erstelle einen unmittelbar versendbaren sachverständigen Erstbericht aus vollständiger Fallakte und Arbeitsauftrag. Der aktuelle Ortstermin und ausdrückliche Feststellungen des Bearbeiters sind maßgeblicher aktueller Sachstand. Der normale Schadenbericht ist vollständig abzuarbeiten: Risiko; Schaden mit Schadenhergang, Schadenursache und Schadenumfang; Ersatzpflicht; Regress; Obliegenheitsverletzungen; Doppelversicherung; Polizei bei ED/Feuer; Handlungsempfehlungen; Kalkulation. Nicht einschlägige Punkte ausdrücklich mit Nein bzw. nicht einschlägig und kurzer Begründung abschließen, nicht kommentarlos leer lassen. Schadenursache und Folgeschaden sauber trennen. KVA/Angebote auslesen, plausibilisieren und Hauptleistungsgruppen mit EUR-Beträgen sowie Netto-, Umsatzsteuer- und Bruttosumme aufnehmen. Belegidentität ausschließlich aus Originalbelegen bestimmen. Rechnungen einzeln mit Aussteller, Rechnungsnummer, Datum und Betrag aufnehmen, soweit geprüft oder belegt. Nichts erfinden.";}
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
    '$allowed=[\'dokumentenindex\',\'rechnungsregister\',\'erstbericht\',\'schadenprotokoll\'',
    '$allowed=[\'dokumentenindex\',\'rechnungsregister\',\'erstbericht\',\'erstbericht_sv_gf\',\'schadenprotokoll\'',
    $source
);

// SV-GF-Template nur injizieren, wenn der Kern noch keine Sonderbehandlung kennt.
if (!str_contains($source, "if(\$key==='erstbericht_sv_gf')")) {
    $sig = 'function gfTemplateFile(string $key,array $meta,string $instructions):?array{';
    $rep = "function gfTemplateFile(string \$key,array \$meta,string \$instructions):?array{if(\$key==='erstbericht_sv_gf'){foreach(gfDriveWalk(GF_KNOWLEDGE_FOLDER_ID,6,250)as\$f)if(strcasecmp((string)(\$f['name']??''),'2026-08-05_GF_Erstbericht_Vorlage_QS_Engel.docx')===0)return\$f;throw new RuntimeException('SV-GF-Erstbericht-Blanco nach QS Engel nicht gefunden.');}";
    $source = str_replace($sig, $rep, $source);
}
$engelSignature = <<<'PHP_CODE'
function gfEngelReport(string $key):bool{return in_array($key,['erstbericht','zwischenbericht','schlussbericht'],true);}
PHP_CODE;
$engelReplacement = <<<'PHP_CODE'
function gfEngelReport(string $key):bool{return in_array($key,['erstbericht_sv_gf','zwischenbericht','schlussbericht'],true);}
PHP_CODE;
$source = str_replace($engelSignature, $engelReplacement, $source);

// Nachtragsausgabe ohne automatisch angehängte Quellen-/Regelwerkslisten.
if (!str_contains($source, "<title>Nachtrag</title>")) {
    $sig = 'function gfDocumentHtml(string $title,array $meta,array $result,array $sources,array $rules,string $userName):string{';
    $rep = <<<'PHP_CODE'
function gfDocumentHtml(string $title,array $meta,array $result,array $sources,array $rules,string $userName):string{if($title==='Nachtrag / Stellungnahme'){$text='';foreach(($result['sections']??[])as$s){$t=trim((string)($s['text']??''));if($t!=='')$text.='<p>'.nl2br(gfH($t)).'</p>';}$caseNo=gfH((string)($meta['schaden_nr']??''));$object=gfH((string)($meta['vn_objekt']??$meta['versicherungsnehmer']??''));return '<!doctype html><html><head><meta charset="utf-8"><title>Nachtrag</title><style>body{font-family:Arial,sans-serif;font-size:11pt;line-height:1.5;margin:2cm;color:#111}h1{font-size:16pt;margin:0 0 18px}p{margin:0 0 12px}.meta{margin-bottom:18px}</style></head><body><h1>Nachtrag</h1><p class="meta"><strong>Schaden-Nr.:</strong> '.$caseNo.($object!==''?'<br><strong>VN / Objekt:</strong> '.$object:'').'</p>'.$text.'<p>Mit freundlichen Grüßen<br><br>Christian Wächter<br>Sachverständiger &amp; Großschadenregulierer<br>DIN EN ISO/IEC 17024 zertifiziert<br>https://www.sv-netzwerk.eu/</p></body></html>';}
PHP_CODE;
    $source = str_replace($sig, $rep, $source);
}

// Widersprüche und Regress nur fachlich relevant ausgeben.
$source = str_replace('Widersprüche ausdrücklich benennen.','Widersprüche nur benennen, wenn sie für den konkreten Arbeitsauftrag oder die Entscheidung relevant sind.',$source);
$source = str_replace('Bei Berichten Regressaussage nicht vergessen.','Regressaussagen nur in Erst-, Zwischen- und Schlussberichten oder bei ausdrücklichem Arbeitsauftrag aufnehmen.',$source);

// Kein harter Abbruch mehr bei bereits im Kern enthaltenen oder leicht geänderten Mustern.
eval($source);
