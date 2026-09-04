<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/intern/api/case-search.php';

function expectSearch(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FEHLER: {$message}\n");
        exit(1);
    }
}

$meta = [
    'schaden_nr' => '26-160966-8',
    'vn_objekt' => 'WEG 30086',
    'strasse' => 'Rheinlandstr. 1+5',
    'plz' => '72250',
    'ort' => 'Freudenstadt',
    'schaden_strasse' => 'Höhenweg 7',
    'versicherungsschein_nr' => 'SV-4711',
    'telefon' => '+49 7441 123456',
    'telefonkontakte' => [['name' => 'Hausmeister', 'nummer' => '0711 9876543']],
];
$text = caseSearchText($meta, '26-160966-8 - WEG 30086');

expectSearch(caseSearchMatches($text, 'Rheinlandstr'), 'Straßenname muss als Teiltreffer gefunden werden.');
expectSearch(caseSearchMatches($text, 'rheinland 72250'), 'Mehrere Suchwörter müssen unabhängig von Satzzeichen funktionieren.');
expectSearch(caseSearchMatches($text, 'Höhenweg'), 'Umlaute in der Suche müssen normalisiert werden.');
expectSearch(caseSearchMatches($text, 'hoehenweg'), 'Umschrift eines Umlauts muss denselben Treffer liefern.');
expectSearch(caseSearchMatches($text, '26-160966'), 'Teil einer Schaden-Nr. muss gefunden werden.');
expectSearch(caseSearchMatches($text, '9876543'), 'Zusätzliche Telefonkontakte müssen in der allgemeinen Fallsuche gefunden werden.');
expectSearch(!caseSearchMatches($text, 'Rheinlandstr Stuttgart'), 'Alle Suchwörter müssen im selben Fall vorkommen.');
expectSearch(caseSearchScore('rheinlandstr 1 5 freudenstadt', 'Rheinlandstr') > caseSearchScore('weg rheinlandstr 1 5', 'Rheinlandstr'), 'Treffer am Anfang müssen höher gewichtet werden.');
expectSearch(casePhoneKey('+49 (0) 7367 393 97 83') === casePhoneKey('0049 7367 3939783'), 'Deutsche Rufnummern müssen unabhängig von Schreibweise übereinstimmen.');
expectSearch(casePhoneKey('0176 12345678') === casePhoneKey('+49 176 12345678'), 'Mobilnummern müssen in nationaler und internationaler Schreibweise übereinstimmen.');
expectSearch(casePhoneMatches(['telefon'=>'+49 7367 3939783'], '07367-393 97 83'), 'Eine hinterlegte VN-Telefonnummer muss erkannt werden.');
expectSearch(casePhoneMatches(['sanierer_mobil'=>'0176 12345678'], '+49 176 12345678'), 'Eine hinterlegte Sanierer-Mobilnummer muss erkannt werden.');
expectSearch(casePhoneMatches(['telefonkontakte'=>[['name'=>'Hausmeister','nummer'=>'0711 9876543']]], '+49 711 9876543'), 'Eine zusätzlich gespeicherte Anrufernummer muss erkannt werden.');
expectSearch(!casePhoneMatches(['telefon'=>'07367 3939783'], '0711 1234567'), 'Abweichende Rufnummern dürfen keinen Fall öffnen.');

echo "OK: Fallsuche normalisiert und bewertet Adressen, Umlaute und Schaden-Nummern korrekt.\n";
