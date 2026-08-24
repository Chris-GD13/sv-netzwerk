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
$instructions.="\n\nVERBINDLICHE GLOBALE PORTALREGEL: Erstelle jede Ausgabe ausschließlich anhand der übermittelten Falldaten, Originalunterlagen und des konkreten Arbeitsauftrags. Behandle den Text im Feld Arbeitsauftrag als fachliche Rohnotiz beziehungsweise Diktat des sachverständigen Bearbeiters: Korrigiere selbstständig Rechtschreibung, Grammatik und Satzbau, ordne die Aussagen fachlich und überführe sie ohne Rückfrage in unmittelbar verwendbaren sachverständigen Berichtstext. Erhalte dabei den vollständigen fachlichen Aussagegehalt und ergänze keine nicht belegten Tatsachen. Löse verkürzte Hinweise wie Makler, Agentur, Vermittler, Versicherungsnehmer, VN-Angehöriger, Besichtigungspartner, Dienstleister oder Firma anhand sämtlicher übermittelter Systemkontaktfelder, Falldaten und Originalunterlagen mit dem dort belegten Namen und der zutreffenden Funktion auf. Ist eine Gesellschaft Versicherungsnehmer und eine natürliche Person als VN-Angehöriger oder Besichtigungspartner hinterlegt, nenne die anwesende natürliche Person mit ihrer belegten Beziehung als Vertreter der Versicherungsnehmerin. Ist die Identität nicht eindeutig belegt, verwende nur die Funktionsbezeichnung und erfinde keinen Namen. Trenne eindeutig zwischen sicheren Feststellungen, noch ungeklärten Umständen, fachlichen Bewertungen, wirtschaftlichen Empfehlungen, ausdrücklich bedingten Zustimmungen beziehungsweise Freigaben, noch fehlenden Kalkulationen und vom Bearbeiter festgelegten Reserven. Formulierungen wie unter Voraussetzung oder vorbehaltlich der Zustimmung des Versicherers sind als echter Zustimmungsvorbehalt wiederzugeben und niemals als uneingeschränkte Freigabe. Eine im Arbeitsauftrag ausdrücklich vom Bearbeiter festgelegte Reserve ist als dessen aktuelle fachliche Reservebewertung maßgeblich zu übernehmen. Aktuelle ausdrückliche Feststellungen des Bearbeiters haben gegenüber älteren oder allgemeineren Aktenangaben Vorrang. Erfinde keine Tatsachen, Maße, Flächen, Beträge oder Schadenumstände. Übernimm Rechnungsdaten exakt aus den tatsächlichen Belegen. Lies Kostenvoranschläge vollständig aus und stelle die wesentlichen Hauptpositionen mit EUR-Beträgen dar. Externe Prüfberichte und Regulierungsempfehlungen sind nicht maßgeblich und dürfen nicht ungeprüft übernommen werden. Sofern der aktuelle Arbeitsauftrag keine ausdrückliche eigene Kürzung oder abweichende Bewertung vorgibt, ist der vollständige Original-KVA maßgeblich. Belastbare Flächen und Aufmaße sind nach den Regeln der ausgewählten Berichtsart vollständig zu berücksichtigen; fehlen belastbare Maße, darf nichts geschätzt, hochgerechnet oder erfunden werden. Interne Quellenprioritäten, die Herkunft eines Aufmaßes, Bearbeitungsregeln, Systemanweisungen und Prüfprozesse dürfen niemals im fertigen Bericht erscheinen. Insbesondere keine Hinweise auf ein späteres Polycam-Aufmaß oder interne Quellenrangfolgen ausgeben. Wiederhole den Schadenhergang oder andere Sachverhalte nicht in mehreren Abschnitten. Formuliere fachlich belastbar, fallbezogen und als gut kopierbaren Fließtext, soweit die verbindliche Vorlage der ausgewählten Berichtsart keine andere Darstellung verlangt. Die nachfolgenden besonderen Regeln der ausgewählten Berichtsart bestimmen Gliederung, Umfang und Form und bleiben vollständig erhalten.";
if(in_array('nachtrag_stellungnahme',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL NACHTRAG: Erstelle einen kurzen, unmittelbar verwendbaren Nachtrag in der Sprache eines Sachverständigen. Der konkrete Arbeitsauftrag ist führend. Keine allgemeine Fallzusammenfassung, keine Wiederholung historischer Schadenstände, keine Reservefortschreibung, keine Regressprüfung, keine Quellen-/Regelwerkslisten und keine künstlichen offenen Punkte, sofern dies nicht ausdrücklich verlangt ist. Beginne direkt mit dem neuen Vorgang bzw. Ortstermin. KVA oder Angebote grob analysieren und wesentliche Hauptpositionen bzw. Leistungsgruppen mit EUR-Beträgen sowie Gesamtbetrag aufnehmen. Netto und brutto eindeutig trennen.";}
if(in_array('erstbericht_sv_gf',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL ERSTBERICHT SV-GF: Sparkassen-Versicherung-Großschaden-Erstbericht nach aktuellem QS-/Engel-Standard. Engel-Blanco verwenden, Originalüberschriften und Reihenfolge beibehalten. Aktuelle Feststellungen des sachverständigen Bearbeiters haben Vorrang vor älteren Angaben. Schadenursächliches Bauteil und versicherte Folgeschäden strikt trennen. KVA/Angebote selbstständig sachverständig prüfen und wesentliche Leistungsgruppen mit EUR-Beträgen und Gesamtbetrag aufnehmen. Externe Prüfberichte, insbesondere von PropertyExpert oder vergleichbaren Prüfdienstleistern, sind nicht maßgeblich und deren Kürzungen oder Regulierungsempfehlungen niemals automatisch übernehmen. Sofern der aktuelle Arbeitsauftrag keine ausdrückliche abweichende Bewertung oder Kürzung vorgibt, gilt der vollständige Original-KVA als Bewertungs- und Freigabebetrag. Diese Regel gilt auch bei der Sparkassen Versicherung. Belegidentität ausschließlich aus Originalbelegen bestimmen. Rechnungen einzeln mit Aussteller, Rechnungsnummer, Datum und Betrag aufnehmen, soweit geprüft oder belegt. Reserve ausschließlich im Abschnitt Reserve EUR und dort nur als Zahl bzw. spartengetrennte Zahlen. Regress belastbar behandeln. Nichts erfinden.";}
if(in_array('erstbericht',$outputs,true)){$instructions.="\n\nVERBINDLICHE PORTALREGEL ALLGEMEINER ERSTBERICHT: Erstelle einen unmittelbar versendbaren sachverständigen Erstbericht aus vollständiger Fallakte und Arbeitsauftrag. Schreibe unter den vorgegebenen fachlichen Überschriften ausschließlich ausformulierte, gut kopierbare Fließtextabsätze; kein Formular, keine Stichpunktmaske und keine Tabellen. Der aktuelle Ortstermin und ausdrückliche Feststellungen des Bearbeiters sind maßgeblicher aktueller Sachstand. Der normale Schadenbericht ist vollständig abzuarbeiten: Risiko; Schadenhergang; Schadenursache; Schadenumfang; Ersatzpflicht; Regress; Obliegenheitsverletzungen; Doppelversicherung; Polizei bei ED/Feuer; Handlungsempfehlungen; Kalkulation. DIE DREI SCHADENABSCHNITTE IMMER EIGENSTÄNDIG UND EINGEHEND BEARBEITEN: Schadenhergang stellt ausschließlich den zeitlichen und tatsächlichen Ablauf dar, insbesondere Schadenzeitpunkt beziehungsweise erstmalige Wahrnehmung, Schadenmeldung, Entwicklung, Sofortmaßnahmen, Ortstermine und maßgebliche Abstimmungen. Bei jedem im Arbeitsauftrag genannten Ortstermin sind sämtliche ausdrücklich genannten Teilnehmer aufzunehmen. Namen und Funktionen aus Falldaten, Systemfeldern und Originalunterlagen auflösen; einen ausdrücklich genannten Versicherungsnehmer, Makler, Vermittler, Dienstleister oder sonstigen Beteiligten niemals weglassen. Ist bei einer ausdrücklich genannten Funktion kein eindeutiger Personenname belegt, die Funktion ohne erfundenen Namen nennen. Schadenursache behandelt ausschließlich das schadenursächliche Bauteil, den technischen Schadenmechanismus, Untersuchungen und Nachweise, den Grad der Feststellungssicherheit sowie verbleibende technische Unklarheiten; sichere Feststellungen und bloße Möglichkeiten ausdrücklich trennen. Schadenumfang beschreibt ausschließlich alle festgestellten und technisch zu erwartenden Auswirkungen, betroffenen Räume, Bauteile und Konstruktionen, Feuchte- und Schadenbilder, notwendige Bauteilöffnungen, Trocknungs-, Rückbau- und Wiederherstellungsbereiche sowie vorhandene belastbare Maße und Aufmaße. Vor Fertigstellung alle Fallunterlagen gezielt nach Einheiten und Mengenangaben wie m², qm, m, lfm, Länge, Breite, Höhe, Umfang, Bodenfläche, Wandfläche und Deckenfläche durchsuchen und sämtliche für den Schadenumfang relevanten belastbaren Werte aufnehmen. Ein vorhandenes Raum- oder Schadenaufmaß darf nicht deshalb entfallen, weil zusätzlich Leitungslängen oder KVA-Mengen vorhanden sind. Alle aus Akte und Arbeitsauftrag verfügbaren relevanten Einzelangaben auswerten; keinen dieser drei Abschnitte mit einer pauschalen Kurzformel abhandeln. Fehlen zu einem Unterpunkt belastbare Angaben, die Lücke knapp benennen und nichts erfinden. JEDEN SACHVERHALT NUR EINMAL DARSTELLEN: Risiko enthält ausschließlich Vertrag, Versicherungsobjekt und Risikomerkmale; Ersatzpflicht enthält nur Deckungsbewertung und Ergebnis ohne erneute Schilderung; Regress, Obliegenheiten, Doppelversicherung und Polizei enthalten nur das jeweilige Prüfergebnis; Handlungsempfehlungen enthalten nur künftige Maßnahmen; Kalkulation enthält ausschließlich Belegprüfung, Beträge, Freigabe und Reserve. Keine Wiederholung des Schadenhergangs, der Ursache, der betroffenen Bereiche oder der Maßnahmen in mehreren Kapiteln. Nicht einschlägige Punkte ausdrücklich mit Nein beziehungsweise nicht einschlägig und kurzer Begründung abschließen, nicht kommentarlos leer lassen. Schadenursache und Folgeschaden sauber trennen. KVA/Angebote auslesen, selbstständig sachverständig prüfen und Hauptleistungsgruppen mit EUR-Beträgen sowie Netto-, Umsatzsteuer- und Bruttosumme aufnehmen. Externe Prüfberichte, insbesondere von PropertyExpert oder vergleichbaren Prüfdienstleistern, sind nicht maßgeblich und deren Kürzungen oder Regulierungsempfehlungen niemals automatisch übernehmen. Sofern der aktuelle Arbeitsauftrag keine ausdrückliche abweichende Bewertung oder Kürzung vorgibt, gilt der vollständige Original-KVA als Bewertungs- und Freigabebetrag. Belegidentität ausschließlich aus Originalbelegen bestimmen. Rechnungen einzeln mit Aussteller, Rechnungsnummer, Datum und Betrag aufnehmen, soweit geprüft oder belegt. AUFMASS IM BERICHT QUELLENNEUTRAL FORMULIEREN: Maße und Flächen als sachverständige Feststellung mit Rechenansatz ausgeben. Im Aufmaßabsatz niemals Firmen-, Dokument-, Angebots-, KVA-, Gutachten-, Prüfbericht- oder Produktnamen und niemals Formulierungen wie laut, gemäß, anhand, aus dem Angebot, im Angebot ausgewiesen, belegt oder übernommen verwenden. Die interne Herkunft und Quellenpriorität darf nicht erkennbar sein.  SACHVERSTÄNDIGE HERLEITUNG STATT AKTENREFERAT: Der Bericht darf die Akte nicht nur zusammenfassen oder Positionen aufzählen, sondern muss aus den belegten Feststellungen eine fachlich nachvollziehbare Bewertung und eine eindeutige, entscheidungsreife Empfehlung ableiten. Den Zusammenhang zwischen Ursache, Leitungsweg, notwendigen Bauteilöffnungen, Trocknungsfähigkeit, Rückbau, Wiederherstellung und wirtschaftlicher Ausführungsvariante konkret erläutern, ohne denselben Sachverhalt in mehreren Kapiteln zu wiederholen. Bei einer Bypass- oder Alternativlösung den technischen Zweck, den gegenüber einer vollständigen Freilegung geringeren Eingriff und die wirtschaftliche Begründung fallbezogen darstellen; einen Zustimmungsvorbehalt des Versicherers vollständig erhalten. Raumgrundflächen, Bauteilflächen und tatsächlich geschädigte Flächen strikt unterscheiden. Eine Raumgröße darf nur als Raum- beziehungsweise Aufmaßangabe bezeichnet und nicht ohne Beleg zur vollständig betroffenen Schadenfläche erklärt werden. Nur gleichartige und räumlich überschneidungsfreie Größen addieren; Boden-, Wand-, Deckenflächen, Raumumfänge und Leitungslängen niemals zu einer Gesamtfläche vermischen. Bei Gipskarton und anderen nur eingeschränkt trocknungsfähigen Bekleidungen konkret trennen, welche Bereiche technisch getrocknet werden können und welche wegen fehlender wirtschaftlicher Trocknungsfähigkeit geöffnet, ausgebaut oder erneuert werden müssen; keine widersprüchlichen Pauschalsätze. Geplante, bereits ausgeführte, angebotene, bezahlte und noch zu kalkulierende Leistungen sprachlich eindeutig unterscheiden. Vermeide unverbindliche Standardsätze wie der KVA ist abzugleichen, die Zahlung ist fortzuführen oder die weitere Vorgehensweise ist abzustimmen, wenn anhand der Akte bereits eine konkrete eigene Bewertung möglich ist. Im Abschnitt Kalkulation die fachliche Prüfung mit Ergebnis formulieren. Wenn keine eigene Kürzung oder abweichende Bewertung vorgegeben ist, den vollständigen Original-KVA mit Netto-, Umsatzsteuer- und Bruttobetrag als maßgeblichen Ansatz benennen; das ist keine erfundene Kürzung oder Fremdprüfung. Reserve und Selbstbehalt strikt trennen: Für die Reserve hat der aktuelle Regulierungsauftrag mit dem Feld Gesamtreserve aktuell beziehungsweise Erstreserve Vorrang vor sonstigen Metadaten und älteren Unterlagen. Selbstbehalt, Forderung, KVA-, Rechnungs- oder Entschädigungsbetrag niemals als Reserve interpretieren. Ist keine Reserve ausdrücklich belegt, keine Reserve ausgeben. Nichts erfinden.";}
$instructions.="\n\nVERBINDLICHE REDAKTIONSREGEL: Der fertige Bericht spricht in sachverständiger Fachsprache über den Fall und niemals über seine Erstellung. Unzulässig sind Metasätze wie vom Bearbeiter festgelegt, vom Bearbeiter als angemessen angesehen, ist zu übernehmen, im Arbeitsauftrag genannt, im System geführt oder anhand der Unterlagen übernommen. Eine ausdrücklich festgelegte Reserve ist neutral und knapp als fachliches Ergebnis zu formulieren, zum Beispiel: Die Schadenreserve wird auf 10.500,00 EUR festgesetzt. Aktuelle konkrete Feststellungen und Beträge aus dem Arbeitsauftrag gehen veralteten Aktenwerten vor. Alte Nullansätze, grobe Schwellenhinweise wie Kosten über 5.000 EUR und überholte Schätzstände dürfen nicht in den Bericht gelangen, wenn ein aktueller konkreter KVA, Rechnungsbetrag oder Reserveansatz vorliegt. Offene Punkte, weitere Termine, Terminabgleiche, Kontaktaufnahmen, Prüfungen oder Freigaben dürfen nur genannt werden, wenn sie im aktuellen Arbeitsauftrag ausdrücklich noch verlangt werden oder sich aus einem eindeutig fortbestehenden sachlichen Erfordernis ergeben. Aus der bloßen Nennung einer Person oder eines früheren Schriftwechsels darf niemals eine künftige Aufgabe erfunden werden. Teilnehmer eines bereits durchgeführten Ortstermins sind ausschließlich als Teilnehmer dieses Termins darzustellen. Schreibe konkret, entscheidungsorientiert und ohne Fülltext, Aktenreferate oder belanglose Wiederholungen.";
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
    "'erstbericht'=>['Risiko','Schadenhergang','Schadenursache','Schadenumfang','Ersatzpflicht','Regress','Obliegenheitsverletzungen','Doppelversicherung','Polizeiliche Ermittlungen','Handlungsempfehlungen','Kalkulation']",
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
$result=gfEngelPrepare($key,gfOpenAI($content,$system),$meta);if($key==='erstbericht'){$generalReview=$content;$generalReview[]=['type'=>'input_text','text'=>"REDAKTIONELLE SCHLUSS-QS: Überarbeite den folgenden Entwurf vollständig und gib wieder ausschließlich das verlangte JSON aus. Die Abschnitte Schadenhergang, Schadenursache und Schadenumfang müssen getrennt, eigenständig und anhand aller verfügbaren Einzelangaben eingehend ausgearbeitet sein. Schadenhergang enthält nur Chronologie, Wahrnehmung, Meldung, Entwicklung, Sofortmaßnahmen, Termine und Abstimmungen. Bei jedem im Arbeitsauftrag erwähnten Termin müssen alle dort genannten Teilnehmer enthalten sein; Namen und Funktionen aus den übermittelten System- und Falldaten auflösen, niemals einen genannten Beteiligten weglassen und niemals einen Namen erfinden. Schadenursache enthält nur schadenursächliches Bauteil, technischen Mechanismus, Untersuchungen, Nachweise, Feststellungssicherheit und verbleibende technische Unklarheiten. Schadenumfang enthält nur betroffene Räume, Bauteile und Konstruktionen, Schaden- und Feuchtebilder, erforderliche Öffnungs-, Trocknungs-, Rückbau- und Wiederherstellungsbereiche sowie belastbare Maße und Aufmaße. Prüfe vor Ausgabe ausdrücklich, ob in den Falldaten Raumflächen, Boden-, Wand- oder Deckenflächen, Längen, Umfänge oder sonstige Mengen mit Einheiten vorhanden sind; sämtliche schadenrelevanten belastbaren Werte müssen im Schadenumfang erscheinen. Leitungslängen und KVA-Mengen ersetzen kein vorhandenes Raum- oder Schadenaufmaß. Pauschale Kurzformulierungen in diesen drei Abschnitten sind unzulässig, sofern weitere relevante Falldaten vorliegen. Entferne ausnahmslos Wiederholungen zwischen den Kapiteln. Jeder Sachverhalt darf nur in seinem zuständigen Kapitel stehen. Das Aufmaß ist als sachverständige Feststellung mit Rechenansätzen darzustellen, jedoch vollständig quellenneutral: Im Aufmaßabsatz keine Firmen-, Dokument-, Angebots-, KVA-, Gutachten-, Prüfbericht- oder Produktnamen und keine Herkunftsformulierungen. Firmen- und Belegnamen sind nur im Kapitel Kalkulation zulässig. Externe Prüfberichte und deren Regulierungsempfehlungen sind nicht maßgeblich. Ohne ausdrückliche abweichende Vorgabe im aktuellen Arbeitsauftrag ist ausschließlich der vollständige Original-KVA als Bewertungs- und Freigabebetrag anzusetzen. Kürzungen aus PropertyExpert oder anderen Fremdprüfungen nicht übernehmen und nicht als eigenen Prüfstand darstellen.  Überarbeite den Text als eigenständige sachverständige Bewertung und nicht als bloßes Aktenreferat. Prüfe die innere technische Logik: Ursache, Leitungsweg, Öffnungsbedarf, Trocknungsfähigkeit, Rückbau, Wiederherstellung und wirtschaftliche Lösungsvariante müssen widerspruchsfrei zusammenpassen. Eine Bypass- oder Alternativlösung ist technisch und wirtschaftlich fallbezogen zu begründen und ein Zustimmungsvorbehalt vollständig zu erhalten. Raumgrößen nicht ohne eindeutigen Beleg als vollständig geschädigte Flächen bezeichnen. Nur gleichartige, überschneidungsfreie Maße addieren; unterschiedliche Flächenarten, Umfänge und Längen getrennt ausweisen. Bei eingeschränkt trocknungsfähigen Bekleidungen klar zwischen trocknungsfähigen Bereichen und erforderlichem Ausbau beziehungsweise Erneuerung unterscheiden. Bereits ausgeführte, angebotene, bezahlte und noch fehlende Leistungen eindeutig auseinanderhalten. Entferne unverbindliche Füllsätze wie der KVA ist abzugleichen, Zahlung fortführen oder weiteres abstimmen, sobald eine konkrete Bewertung möglich ist. Kalkulation nicht nur aufzählen: eigenes Prüfergebnis formulieren und ohne ausdrückliche eigene Kürzung den vollständigen Original-KVA als maßgeblichen Ansatz benennen. Reserve ausschließlich aus dem aktuellen Regulierungsauftrag, Feld Gesamtreserve aktuell beziehungsweise Erstreserve, übernehmen; niemals Selbstbehalt oder KVA als Reserve.\n\nZU ÜBERARBEITENDER ENTWURF:\n".json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];$result=gfEngelPrepare($key,gfOpenAI($generalReview,$system),$meta);$generalHeadings=gfHeadings($key);$generalSections=is_array($result['sections']??null)?array_values($result['sections']):[];if(count($generalSections)!==count($generalHeadings))throw new RuntimeException('Allgemeiner Erstbericht unvollständig: Erwartet werden '.count($generalHeadings).' fachliche Fließtextabschnitte.');foreach($generalHeadings as$generalIndex=>$generalHeading){$generalSections[$generalIndex]['heading']=$generalHeading;$generalSections[$generalIndex]['text']=str_ireplace(['schaden ursächlich','schaden ursächliche','schaden ursächlichen'],['schadenursächlich','schadenursächliche','schadenursächlichen'],(string)($generalSections[$generalIndex]['text']??''));}$result['sections']=$generalSections;}$contentQs=gfEngelValidate($key,$result,$meta,$instructions);
PHP_CODE;
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
$generalReview[]=['type'=>'input_text','text'=>'VERBINDLICHE KOPFPRÜFUNG: Gib zusätzlich case_header als JSON-Objekt mit den Schlüsseln versicherungsnehmer, vn_strasse, vn_plz, vn_ort, schaden_strasse, schaden_plz und schaden_ort aus. Ermittle jeden Wert ausschließlich aus eindeutigen Originalunterlagen. Versicherungsnehmer ist der Name beziehungsweise die Firma, nicht nur Straße oder Objektbezeichnung. VN-Anschrift und Schadenort strikt trennen. Gib bei fehlendem eindeutigen Nachweis für einen Einzelwert eine leere Zeichenfolge aus; nichts ergänzen oder kombinieren.'];$result=gfEngelPrepare($key,gfOpenAI($generalReview,$system),$meta);if(is_array($result['case_header']??null)){$verified=$result['case_header'];$headerMap=['versicherungsnehmer'=>'vn_objekt','vn_strasse'=>'strasse','vn_plz'=>'plz','vn_ort'=>'ort','schaden_strasse'=>'schaden_strasse','schaden_plz'=>'schaden_plz','schaden_ort'=>'schaden_ort'];foreach($headerMap as$from=>$to){$value=trim(preg_replace('/\s+/u',' ',(string)($verified[$from]??''))??'');if($value!==''&&mb_strlen($value,'UTF-8')<=160)$meta[$to]=$value;}}
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

// Widersprüche und Regress nur fachlich relevant ausgeben.
$source = str_replace('Widersprüche ausdrücklich benennen.','Widersprüche nur benennen, wenn sie für den konkreten Arbeitsauftrag oder die Entscheidung relevant sind.',$source);
$source = str_replace('Bei Berichten Regressaussage nicht vergessen.','Regressaussagen nur in Erst-, Zwischen- und Schlussberichten oder bei ausdrücklichem Arbeitsauftrag aufnehmen.',$source);

// Allgemeine Erstberichte mit redaktionellen Metaformulierungen oder erfundenen
// Folgeaufgaben dürfen die QS nicht passieren.
$generalQsNeedle = '$contentQs=gfEngelValidate($key,$result,$meta,$instructions);';
$generalQsReplacement = <<<'PHP_CODE'
if($key==='erstbericht'){$generalFaults=[];$generalText=gfEngelText($result);if(preg_match('/\b(?:vom\s+Bearbeiter|im\s+Arbeitsauftrag|im\s+System\s+(?:geführt|hinterlegt)|ist\s+(?:als\s+[^.]{0,80}\s+)?zu\s+übernehmen|anhand\s+der\s+Unterlagen\s+übernommen)\b/ui',$generalText))$generalFaults[]='interne Bearbeitungs- oder Herkunftssprache';if(preg_match('/\b(?:Gesamtansatz|Kostenschätzung)\b[^.]{0,100}\b0(?:,00)?\s*(?:EUR|€)/ui',$generalText)&&preg_match('/\b(?:Reserve|KVA|Kostenvoranschlag)\b[^.]{0,100}\b[1-9][0-9.]*,[0-9]{2}\s*(?:EUR|€)/ui',$generalText))$generalFaults[]='veralteter Nullansatz neben aktuellem Betrag';if(preg_match('/\bTerminabgleich\b|\bTermin\s+mit\s+[^.]{0,80}\b(?:offen|abzustimmen)\b/ui',$generalText)&&!preg_match('/\bTermin(?:abgleich)?\b[^.]{0,100}\b(?:offen|abstimmen|vereinbaren|erforderlich)\b/ui',$instructions))$generalFaults[]='nicht beauftragter zukünftiger Termin';if($generalFaults){$repair=$content;$repair[]=['type'=>'input_text','text'=>"VERBINDLICHE FEHLERKORREKTUR VOR DER AUSGABE: Der Entwurf enthält folgende unzulässige Punkte: ".implode(', ',$generalFaults).". Korrigiere den vollständigen Bericht und gib ausschließlich das verlangte JSON mit exakt denselben Überschriften aus. Schreibe nur fachliche Feststellungen und Bewertungen, niemals über Bearbeiter, Arbeitsauftrag, System, Quellenübernahme oder den Erstellungsprozess. Formuliere die Reserve neutral als fachliches Ergebnis. Entferne veraltete Nullansätze und grobe Kostenschwellen, wenn aktuelle konkrete Beträge vorliegen. Erfinde aus genannten Personen keine Termine, Kontaktaufnahmen oder offenen Aufgaben. Erhalte alle belegten Teilnehmer, Feststellungen, Beträge und Aufmaße.\n\nFEHLERHAFTER ENTWURF:\n".json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];$result=gfEngelPrepare($key,gfOpenAI($repair,$system),$meta);$generalHeadings=gfHeadings($key);$generalSections=is_array($result['sections']??null)?array_values($result['sections']):[];if(count($generalSections)!==count($generalHeadings))throw new RuntimeException('Automatische Berichtskorrektur unvollständig.');foreach($generalHeadings as$generalIndex=>$generalHeading)$generalSections[$generalIndex]['heading']=$generalHeading;$result['sections']=$generalSections;$generalText=gfEngelText($result);if(preg_match('/\b(?:vom\s+Bearbeiter|im\s+Arbeitsauftrag|im\s+System\s+(?:geführt|hinterlegt)|ist\s+(?:als\s+[^.]{0,80}\s+)?zu\s+übernehmen|anhand\s+der\s+Unterlagen\s+übernommen)\b/ui',$generalText))throw new RuntimeException('Automatische Berichtskorrektur konnte interne Bearbeitungssprache nicht vollständig entfernen.');if(preg_match('/\bTerminabgleich\b|\bTermin\s+mit\s+[^.]{0,80}\b(?:offen|abzustimmen)\b/ui',$generalText)&&!preg_match('/\bTermin(?:abgleich)?\b[^.]{0,100}\b(?:offen|abstimmen|vereinbaren|erforderlich)\b/ui',$instructions))throw new RuntimeException('Automatische Berichtskorrektur konnte einen unbelegten Termin nicht entfernen.');}}
$contentQs=gfEngelValidate($key,$result,$meta,$instructions);
PHP_CODE;
$source = str_replace($generalQsNeedle, trim($generalQsReplacement), $source);
$generalFaultInit = "if(\$key==='erstbericht'){\$generalFaults=[];";
$generalFaultInitExtended = <<<'PHP_CODE'
if($key==='erstbericht'){$generalFaults=[];$generalHergang='';$generalUmfang='';foreach(($result['sections']??[])as$generalSection){$generalSectionHeading=gfNorm((string)($generalSection['heading']??''));if($generalSectionHeading==='schadenhergang')$generalHergang=(string)($generalSection['text']??'');if($generalSectionHeading==='schadenumfang')$generalUmfang=(string)($generalSection['text']??'');}if(preg_match('/\b(?:Makler|Agentur|Vermittler)\b/ui',$instructions)&&!preg_match('/\b(?:Makler|Agentur|Vermittler)\b/ui',$generalHergang))$generalFaults[]='der im aktuellen Ortstermin genannte Makler beziehungsweise Vermittler fehlt im Schadenhergang und muss mit belegtem Namen und Funktion aufgenommen werden';if(preg_match('/\b(?:nach\s+den\s+vorliegenden|laut|gemäß|anhand|aus\s+dem|im\s+(?:Angebot|KVA)|belegt|übernommen)\b[^.]{0,100}\b(?:m²|qm|lfm)\b/ui',$generalUmfang))$generalFaults[]='das Aufmaß enthält einen unzulässigen Herkunftshinweis und muss als unmittelbare sachverständige Flächenfeststellung formuliert werden';
PHP_CODE;
$source = str_replace($generalFaultInit, trim($generalFaultInitExtended), $source, $countGeneralFaultExtension);
if ($countGeneralFaultExtension !== 1) throw new RuntimeException('Teilnehmer- und Aufmaß-QS konnte nicht sicher angebunden werden.');
$generalContentQsNeedle = '$contentQs=gfEngelValidate($key,$result,$meta,$instructions);';
$generalContentQsExtended = <<<'PHP_CODE'
if($key==='erstbericht'){$checkedHergang='';$checkedUmfang='';foreach(($result['sections']??[])as$checkedSection){$checkedHeading=gfNorm((string)($checkedSection['heading']??''));if($checkedHeading==='schadenhergang')$checkedHergang=(string)($checkedSection['text']??'');if($checkedHeading==='schadenumfang')$checkedUmfang=(string)($checkedSection['text']??'');}if(preg_match('/\b(?:Makler|Agentur|Vermittler)\b/ui',$instructions)&&!preg_match('/\b(?:Makler|Agentur|Vermittler)\b/ui',$checkedHergang)){$broker=trim((string)($meta['vermittler_firma']??$meta['vermittler_ansprechpartner']??''));if(preg_match('/[@0-9]/u',$broker))$broker='';$vnContact=trim((string)($meta['kontakt']??''));if(preg_match('/[@0-9]/u',$vnContact))$vnContact='';$vnParticipant=$vnContact!==''?$vnContact.' für den Versicherungsnehmer':'der Versicherungsnehmer beziehungsweise dessen Vertretung';$brokerParticipant=$broker!==''?$broker.' als betreuender Vermittler':'der betreuende Makler';$participantText='Am aktuellen Ortstermin nahmen '.$vnParticipant.' sowie '.$brokerParticipant.' teil.';foreach(($result['sections']??[])as$participantIndex=>$participantSection)if(gfNorm((string)($participantSection['heading']??''))==='schadenhergang'){$existing=trim((string)($result['sections'][$participantIndex]['text']??''));$result['sections'][$participantIndex]['text']=trim($existing.' '.$participantText);break;}}if(preg_match('/\b(?:nach\s+den\s+vorliegenden|laut|gemäß|anhand|aus\s+dem|im\s+(?:Angebot|KVA)|belegt|übernommen)\b[^.]{0,100}\b(?:m²|qm|lfm)\b/ui',$checkedUmfang))throw new RuntimeException('Allgemeiner Erstbericht redaktionell unzulässig: Herkunftshinweis im Aufmaß erkannt.');}
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
$draftReplacement = '$contentQs=gfEngelValidate($key,$result,$meta,$instructions);$drafts[]=[\'type\'=>$key,\'title\'=>$title,\'content\'=>gfPortalDraftText($title,$result)];if(!empty($order[\'draft_only\'])){gfJobUpdate($jobId,\'running\',min(94,$base+(int)floor(70/$total)),$title.\' wurde als bearbeitbarer Entwurf erstellt.\');continue;}if(gfExcelOutput($key))';
$source = str_replace($draftNeedle, $draftReplacement, $source, $countDraftResult);
if ($countDraftResult !== 1) throw new RuntimeException('Entwurfsrückgabe konnte nicht an die Dokumenterstellung angebunden werden.');
$finalNeedle = '$result=[\'ok\'=>true,\'created\'=>$created,\'count\'=>count($created),';
$finalReplacement = '$result=[\'ok\'=>true,\'created\'=>$created,\'drafts\'=>$drafts,\'count\'=>count($created),';
$source = str_replace($finalNeedle, $finalReplacement, $source, $countDraftResponse);
if ($countDraftResponse !== 1) throw new RuntimeException('Entwurf konnte nicht in die Antwort übernommen werden.');
$source = str_replace("gfJobUpdate(\$jobId,'done',100,'Dokumentpaket vollständig erstellt.',\$result);", "gfJobUpdate(\$jobId,'done',100,!empty(\$order['draft_only'])?'Entwurf vollständig erstellt.':'Dokumentpaket vollständig erstellt.',\$result);", $source);

// Kein harter Abbruch mehr bei bereits im Kern enthaltenen oder leicht geänderten Mustern.
eval($source);
