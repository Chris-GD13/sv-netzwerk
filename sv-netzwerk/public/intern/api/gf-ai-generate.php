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

// Nachträge bewusst kurz und unmittelbar verwendbar halten.
$oldHeadings = <<<'TXT'
'nachtrag_stellungnahme'=>['Bezug / bisheriger Prüfstand','Neu vorliegende Unterlagen','Feststellungen','Fachliche und wirtschaftliche Bewertung','Entscheidung / Empfehlung','Auswirkungen auf Schadenhöhe / Reserve','Weiteres Vorgehen']
TXT;
$newHeadings = <<<'TXT'
'nachtrag_stellungnahme'=>['Nachtrag']
TXT;
$source = str_replace($oldHeadings, $newHeadings, $source, $headingsPatched);

$oldInstructions = <<<'TXT'
$instructions=trim((string)($order['instructions']??''));if($instructions==='')throw new RuntimeException('Arbeitsauftrag fehlt.');
TXT;
$newInstructions = <<<'TXT'
$instructions=trim((string)($order['instructions']??''));if($instructions==='')throw new RuntimeException('Arbeitsauftrag fehlt.');if(in_array('nachtrag_stellungnahme',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL NACHTRAG: Erstelle einen kurzen, unmittelbar verwendbaren Nachtrag in der Sprache eines Sachverständigen. Der konkrete Arbeitsauftrag des Bearbeiters ist führend. Keine allgemeine Fallzusammenfassung, keine Wiederholung historischer Schadenstände, keine Reservefortschreibung, keine Regressprüfung, keine Liste ausgewerteter Unterlagen oder Regelwerke und keine künstlich erzeugten offenen Punkte, sofern dies nicht ausdrücklich verlangt ist. Beginne direkt mit dem neuen Vorgang bzw. Ortstermin. Wenn ein KVA oder Angebot vorliegt oder im Arbeitsauftrag genannt wird, analysiere es grob und nenne die wesentlichen Hauptpositionen bzw. Leistungsgruppen mit konkreten EUR-Beträgen sowie den Gesamtbetrag. Unterscheide netto und brutto eindeutig. Formuliere knapp, nachvollziehbar und regulierungstauglich. Der Abschnitt trägt ausschließlich die Überschrift Nachtrag.";}if(in_array('erstbericht_sv_gf',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL ERSTBERICHT SV-GF: Dies ist der Sparkassen-Versicherung-Großschaden-Erstbericht nach dem aktuellen QS-/Engel-Standard. Verwende das dafür hinterlegte Engel-Erstbericht-Blanco. Die aktuellen Feststellungen des sachverständigen Bearbeiters im Arbeitsauftrag haben Vorrang vor älteren oder widersprüchlichen Angaben von Dienstleistern, Maklern oder Versicherungsnehmern; solche Altangaben nicht als aktuelle Ursache fortschreiben. Die Originalüberschriften und deren Reihenfolge bleiben unverändert. Schadenursächliches Bauteil und versicherte Folgeschäden strikt trennen. Nicht versicherte Ursache nicht als versicherten Schaden freigeben. KVA/Angebote auslesen, Plausibilität grob prüfen und bei positiver Bewertung die wesentlichen Leistungsgruppen mit EUR-Beträgen und Gesamtbetrag im fachlich passenden Abschnitt nennen. Vorliegende Rechnungen einzeln mit Aussteller, Rechnungsnummer und Betrag aufnehmen, wenn der Bearbeiter sie als geprüft/ordnungsgemäß bezeichnet oder die Akte dies belegt; keine Rechnungen oder Beträge erfinden. Die Reserve darf ausschließlich im Abschnitt Reserve EUR und dort nur als Zahl bzw. spartengetrennte Zahlen stehen. Außerhalb dieses Abschnitts keine Reserve nennen oder Schadenhöhe pauschal kalkulieren. Regress muss ausdrücklich und belastbar behandelt werden. Beträge, Selbstbehalt, Teilzahlungen und Freigaben nur aus der Akte übernehmen; nichts erfinden.";}if(in_array('erstbericht',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL ALLGEMEINER ERSTBERICHT: Erstelle einen unmittelbar versendbaren sachverständigen Erstbericht aus vollständiger Fallakte und Arbeitsauftrag. Der aktuelle Ortstermin und die ausdrücklichen Feststellungen des sachverständigen Bearbeiters im Arbeitsauftrag sind der maßgebliche aktuelle Sachstand und haben Vorrang vor älteren, vorläufigen oder widersprüchlichen Angaben von Dienstleistern, Maklern oder Versicherungsnehmern. Solche Altangaben dürfen nicht als aktuelle Ursache fortgeschrieben werden. Verwende das allgemeine Erstbericht-Blanco und fülle dessen Originalabschnitte fachlich nachvollziehbar aus. Schadenursache und versicherten Folgeschaden strikt trennen: Ist das schadenursächliche Bauteil nach Feststellung des Bearbeiters nicht versichert, ist dies unter Ersatzpflicht klar zu benennen; versicherte Folgeschäden sind davon getrennt als gedeckt zu bewerten. KVA/Angebote sind auszulesen, grob auf Plausibilität und Schadenbezug zu prüfen und bei positiver Bewertung mit den wesentlichen Leistungsgruppen in EUR sowie Netto-, Umsatzsteuer- und Bruttosumme im Bericht aufzunehmen. Vorliegende Rechnungen sind einzeln mit Aussteller, Rechnungsnummer, Datum und Betrag aufzunehmen, wenn der Bearbeiter sie als in Ordnung bezeichnet oder die Akte die Freigabe trägt. Fehlt eine Rechnung tatsächlich in der Fallakte, keinen Betrag erfinden, sondern nur die vorhandenen Belege verarbeiten. Keine Engel-spezifischen Zusatzregeln erzwingen, sofern der Arbeitsauftrag dies nicht verlangt.";}
TXT;
$source = str_replace($oldInstructions, $newInstructions, $source, $instructionsPatched);

$source = str_replace(
    'Widersprüche ausdrücklich benennen.',
    'Widersprüche nur benennen, wenn sie für den konkreten Arbeitsauftrag oder die zu treffende Entscheidung tatsächlich relevant sind.',
    $source
);
$source = str_replace(
    'Bei Berichten Regressaussage nicht vergessen.',
    'Regressaussagen nur in Erst-, Zwischen- und Schlussberichten oder bei ausdrücklichem Arbeitsauftrag aufnehmen.',
    $source
);

// Zwei getrennte Erstbericht-Typen bereitstellen.
$source = str_replace(
    "'erstbericht'=>'Erstbericht'",
    "'erstbericht'=>'Allgemeiner Erstbericht','erstbericht_sv_gf'=>'Erstbericht SV-GF (QS Engel)'",
    $source,
    $titlePatched
);
$source = str_replace(
    "'erstbericht'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress']",
    "'erstbericht'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress'],'erstbericht_sv_gf'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang/-ursache','Polizeiliche Ermittlungen','Ersatzpflicht','Schadenumfang','Reserve EUR','Schadenabwicklung','Teilzahlung','Regress']",
    $source,
    $svHeadingsPatched
);
$source = str_replace(
    "$allowed=['dokumentenindex','rechnungsregister','erstbericht','schadenprotokoll'",
    "$allowed=['dokumentenindex','rechnungsregister','erstbericht','erstbericht_sv_gf','schadenprotokoll'",
    $source,
    $allowedPatched
);

// SV-GF-Erstbericht verwendet das explizite Engel-Blanco aus der Wissensbasis.
$templateSignature = 'function gfTemplateFile(string $key,array $meta,string $instructions):?array{';
$templateReplacement = <<<'PHP_CODE'
function gfTemplateFile(string $key,array $meta,string $instructions):?array{if($key==='erstbericht_sv_gf'){foreach(gfDriveWalk(GF_KNOWLEDGE_FOLDER_ID,6,250)as$f)if(strcasecmp((string)($f['name']??''),'2026-08-05_GF_Erstbericht_Vorlage_QS_Engel.docx')===0)return$f;throw new RuntimeException('SV-GF-Erstbericht-Blanco nach QS Engel nicht gefunden.');}
PHP_CODE;
$source = str_replace($templateSignature, $templateReplacement, $source, $templatePatched);

// Engel-QS nur dort erzwingen, wo sie fachlich vorgesehen ist; allgemeiner Erstbericht bleibt allgemein.
$source = str_replace(
    "function gfEngelReport(string $key):bool{return in_array($key,['erstbericht','zwischenbericht','schlussbericht'],true);}",
    "function gfEngelReport(string $key):bool{return in_array($key,['erstbericht_sv_gf','zwischenbericht','schlussbericht'],true);}",
    $source,
    $engelPatched
);

// Nachtragsausgabe ohne automatisch angehängte Quellen-/Regelwerkslisten.
$docFunction = 'function gfDocumentHtml(string $title,array $meta,array $result,array $sources,array $rules,string $userName):string{';
$docReplacement = <<<'PHP_CODE'
function gfDocumentHtml(string $title,array $meta,array $result,array $sources,array $rules,string $userName):string{if($title==='Nachtrag / Stellungnahme'){$text='';foreach(($result['sections']??[])as$s){$t=trim((string)($s['text']??''));if($t!=='')$text.='<p>'.nl2br(gfH($t)).'</p>';}$caseNo=gfH((string)($meta['schaden_nr']??''));$object=gfH((string)($meta['vn_objekt']??$meta['versicherungsnehmer']??''));return '<!doctype html><html><head><meta charset="utf-8"><title>Nachtrag</title><style>body{font-family:Arial,sans-serif;font-size:11pt;line-height:1.5;margin:2cm;color:#111}h1{font-size:16pt;margin:0 0 18px}p{margin:0 0 12px}.meta{margin-bottom:18px}</style></head><body><h1>Nachtrag</h1><p class="meta"><strong>Schaden-Nr.:</strong> '.$caseNo.($object!==''?'<br><strong>VN / Objekt:</strong> '.$object:'').'</p>'.$text.'<p>Mit freundlichen Grüßen<br><br>Christian Wächter<br>Sachverständiger &amp; Großschadenregulierer<br>DIN EN ISO/IEC 17024 zertifiziert<br>https://www.sv-netzwerk.eu/</p></body></html>';}
PHP_CODE;
$source = str_replace($docFunction, $docReplacement, $source, $documentPatched);

if (
    $headingsPatched !== 1 || $instructionsPatched !== 1 || $documentPatched !== 1 ||
    $titlePatched !== 1 || $svHeadingsPatched !== 1 || $allowedPatched !== 1 ||
    $templatePatched !== 1 || $engelPatched !== 1
) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Portalregeln konnten nicht sicher auf den aktuellen KI-Kern angewendet werden.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

eval($source);
