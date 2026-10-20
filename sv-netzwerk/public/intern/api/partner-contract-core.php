<?php
declare(strict_types=1);

const PC_VERSION = '2026-10-20.1';

function pcFields(): array {
    return [
        'operator_name'=>'Sachverständigenbüro Marc Schütt e.K.',
        'operator_address'=>"Feldstraße 108\n58791 Werdohl",
        'operator_representative'=>'Inhaber Marc Schütt',
        'operator_register'=>'Amtsgericht Iserlohn, HRA 5477',
        'operator_email'=>'info@sv-netzwerk.eu',
        'partner_name'=>'', 'partner_address'=>'', 'partner_representative'=>'',
        'partner_register'=>'', 'partner_email'=>'', 'partner_phone'=>'',
        'fields'=>'', 'region'=>'', 'qualifications'=>'', 'commission'=>'',
        'place'=>'', 'date'=>'', 'profile_permission'=>'no',
    ];
}
function pcClean(array $input): array {
    $out=[];
    foreach(pcFields() as $key=>$default) {
        $value=$input[$key]??$default;
        if(!is_scalar($value)) throw new InvalidArgumentException('Ungültiges Feld: '.$key);
        $value=trim((string)$value);
        if(mb_strlen($value)>($key==='qualifications'?4000:1500)) throw new InvalidArgumentException('Feld zu lang: '.$key);
        if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value)) throw new InvalidArgumentException('Ungültige Steuerzeichen.');
        $out[$key]=$value;
    }
    foreach(['operator_email','partner_email'] as $key) if($out[$key]!==''&&!filter_var($out[$key],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Bitte gültige E-Mail-Adresse eintragen.');
    if($out['commission']!=='') {
        $v=str_replace(',','.',$out['commission']);
        if(!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/',$v)||(float)$v<=0||(float)$v>=100) throw new InvalidArgumentException('Provision muss größer als 0 und kleiner als 100 Prozent sein (höchstens zwei Nachkommastellen).');
        $out['commission']=str_replace('.',',',rtrim(rtrim(number_format((float)$v,2,'.',''),'0'),'.'));
    }
    if(!in_array($out['profile_permission'],['no','yes'],true)) throw new InvalidArgumentException('Ungültige Profilfreigabe.');
    if($out['date']!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$out['date']) || date('Y-m-d',strtotime($out['date'])?:0)!==$out['date'])) throw new InvalidArgumentException('Ungültiges Vertragsdatum.');
    return $out;
}
function pcMissing(array $d): array {
    $labels=['operator_name'=>'Netzwerkbetreiber','operator_address'=>'Anschrift Netzwerkbetreiber','operator_representative'=>'Vertretung Netzwerkbetreiber','operator_email'=>'E-Mail Netzwerkbetreiber','partner_name'=>'Partner / vollständige Firma','partner_address'=>'Partneranschrift','partner_representative'=>'Inhaber / Vertretung Partner','partner_email'=>'Partner-E-Mail','fields'=>'Fachgebiete','region'=>'Einsatzgebiet','qualifications'=>'Qualifikationsangaben','commission'=>'Provisionssatz','place'=>'Vertragsort','date'=>'Vertragsdatum'];
    return array_values(array_filter($labels,fn($label,$key)=>trim((string)($d[$key]??''))==='',ARRAY_FILTER_USE_BOTH));
}
function pcSchema(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS partner_contracts (id VARCHAR(36) PRIMARY KEY, owner_id INTEGER NOT NULL, revision INTEGER NOT NULL, payload_json TEXT NOT NULL, template_version VARCHAR(30) NOT NULL, archived INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL)');
}
function pcGet(PDO $db,string $id,int $owner): array {
    $q=$db->prepare('SELECT * FROM partner_contracts WHERE id=? AND owner_id=?');$q->execute([$id,$owner]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r) throw new RuntimeException('Vertrag nicht gefunden.',404);
    $r['data']=json_decode($r['payload_json'],true,512,JSON_THROW_ON_ERROR);unset($r['payload_json']);
    $r['missing']=pcMissing($r['data']);return $r;
}
function pcSave(PDO $db,string $id,int $owner,int $revision,array $data): array {
    if(!preg_match('/^[a-f0-9-]{36}$/D',$id)) throw new InvalidArgumentException('Ungültige Vertragskennung.');
    $d=pcClean($data);$json=json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$now=gmdate('Y-m-d H:i:s');
    if($revision===0) {
        try {$q=$db->prepare('INSERT INTO partner_contracts(id,owner_id,revision,payload_json,template_version,created_at,updated_at) VALUES(?,?,1,?,?,?,?)');$q->execute([$id,$owner,$json,PC_VERSION,$now,$now]);}
        catch(PDOException $e) {if(!in_array((string)$e->getCode(),['23000','23505','19'],true))throw $e;throw new RuntimeException('Dieser Entwurf wurde bereits gespeichert. Bitte neu öffnen.',409);}
    } else {
        $q=$db->prepare('UPDATE partner_contracts SET payload_json=?,revision=revision+1,template_version=?,updated_at=? WHERE id=? AND owner_id=? AND revision=? AND archived=0');$q->execute([$json,PC_VERSION,$now,$id,$owner,$revision]);
        if($q->rowCount()!==1)throw new RuntimeException('Zwischenzeitliche Änderung oder archivierter Entwurf. Bitte neu öffnen; Ihre Eingaben wurden nicht überschrieben.',409);
    }
    return pcGet($db,$id,$owner);
}
function pcSections(array $d): array {
    $rate=($d['commission']?:'________').' %';
    return [
        ['1. Zusammenarbeit und Selbstständigkeit', [
            'Der Netzwerkbetreiber führt das SV-Netzwerk Bau · Schaden · Regulierung. Der Partner wird mit seinen nachgewiesenen Fachgebieten in das Netzwerk selbstständig tätiger Fachleute aufgenommen. Beide Parteien handeln bei Abschluss dieses Vertrags in Ausübung ihrer gewerblichen oder selbstständigen beruflichen Tätigkeit; dieser Vertrag richtet sich nicht an Verbraucher.',
            'Das Netzwerk ist keine offene Auftrags- oder Schadenreguliererbörse. Es kann fachlich passende Anfragen von Versicherern, Unternehmen und Privatpersonen zu Gutachten, Prüfungen und vergleichbaren Fachleistungen sowie Mengenanfragen in Kumulzeiten an geeignete Partner weitergeben. Die Aufnahme begründet keinen Anspruch auf Anfragen, Aufträge, Mindestumsatz oder eine bestimmte Auslastung. Es bestehen weder Exklusivität noch eine Pflicht zur Annahme einzelner Aufträge.',
            'Es entstehen kein Arbeitsverhältnis, keine Gesellschaft zwischen den Parteien und keine Befugnis, die andere Partei rechtsgeschäftlich zu vertreten. Der Partner bestimmt seine Tätigkeit eigenverantwortlich; die tatsächliche Durchführung hat seiner selbstständigen Stellung zu entsprechen. Es besteht keine dauernde Verpflichtung zur Kundenwerbung oder Vermittlung für die andere Partei.'
        ]],
        ['2. Aufnahme und einzelne Aufträge', [
            'Die Aufnahme und Mitgliedschaft sind kostenfrei. Es werden keine Aufnahmegebühren, laufenden Mitgliedsbeiträge oder sonstigen versteckten Netzwerkgebühren erhoben. Eine Vergütung des Netzwerkbetreibers kommt ausschließlich als vorab vereinbarte prozentuale Provision für einen konkreten, durch ihn nachgewiesenen oder vermittelten Auftrag in Betracht.',
            'Vor jeder provisionspflichtigen Weitergabe bestätigen beide Parteien in Textform, zum Beispiel per E-Mail, mindestens den eindeutig bezeichneten Auftrag beziehungsweise Auftraggeber, den Leistungsumfang, den Vertragspartner des Kunden, den Provisionssatz und die Berechnungsgrundlage. Dafür kann Anlage 1 verwendet werden. Der Satz dieses Rahmenvertrags ist lediglich der vorgeschlagene Standardsatz. Ohne vorherige ausdrückliche Einigung über den konkreten Auftrag entsteht aus diesem Rahmenvertrag kein Provisionsanspruch. Schweigen, die bloße Aufnahme oder ein Profileintrag gelten nicht als Zustimmung.',
            'Regelfall ist der direkte Leistungsvertrag zwischen Partner und Kunde. Der Partner vereinbart Leistungsumfang, Vergütung, Termine und etwaige gesetzliche Informations- und Widerrufspflichten selbst mit dem Kunden und rechnet unmittelbar mit ihm ab. Soll stattdessen der Netzwerkbetreiber den Kundenvertrag schließen und den Partner beauftragen, bedarf dies eines gesonderten Leistungsauftrags vor Tätigkeitsbeginn. Dieser Rahmenvertrag begründet keinen solchen Unterauftrag und keine doppelte Vergütung.'
        ]],
        ['3. Provision und Abrechnung', [
            'Vorgeschlagener Provisionssatz für ausdrücklich bestätigte Einzelaufträge: '.$rate.'. Provisionsschuldner ist ausschließlich der Partner; gegenüber dem Kunden begründet diese Vereinbarung keine Zahlungspflicht. Bemessungsgrundlage ist das tatsächlich beim Partner eingegangene Nettohonorar für die in der Einzelvereinbarung bezeichnete eigene Fachleistung. Umsatzsteuer, gesondert ausgewiesene Reise- und Übernachtungskosten, Auslagen und durchlaufende Fremdkosten sind ausgenommen. Rabatte, Gutschriften und Erstattungen vermindern die Grundlage. Die Provision ist selbst ein Nettobetrag zuzüglich gesetzlich geschuldeter Umsatzsteuer.',
            'Die Provision setzt voraus, dass der bezeichnete Kundenvertrag infolge des Nachweises oder der Vermittlung des Netzwerkbetreibers wirksam zustande kommt und der Partner daraus eine Honorarzahlung erhält. Sie entsteht anteilig mit dem jeweiligen Zahlungseingang. Ohne wirksamen Kundenvertrag oder ohne Honorarzahlung fällt keine Provision an. Bei Rückzahlung des Honorars ist die darauf entfallende Provision zu korrigieren und eine Überzahlung zu erstatten. Ein vorheriger Kundenkontakt oder ein unabhängig zustande gekommener Auftrag begründet für sich keinen Anspruch.',
            'Der Partner teilt Auftragsabschluss und provisionsrelevante Zahlungseingänge innerhalb von 14 Kalendertagen mit und übermittelt eine nachvollziehbare Abrechnung mit Auftragsbezug, Nettohonorar und Zahlungseingang. Erforderliche Belege dürfen um nicht abrechnungsrelevante personenbezogene Daten geschwärzt werden. Der Netzwerkbetreiber stellt eine ordnungsgemäße Provisionsrechnung. Sie ist 14 Kalendertage nach Zugang, frühestens nach dem entsprechenden Honorarzahlungseingang, fällig. Kein allgemeines Einsichtsrecht in fremde Kundenakten wird eingeräumt.',
            'Folgeaufträge, Vertragsverlängerungen, Zusatzleistungen und weitere Kunden desselben Unternehmens sind nicht automatisch provisionspflichtig; hierfür ist jeweils eine vorherige ausdrückliche Vereinbarung erforderlich. Aufwendungsersatz, Vertragsstrafen und pauschale Ausfallvergütungen werden nicht vereinbart. Eine Kündigung beseitigt bereits wirksam vereinbarte Ansprüche aus konkret bestätigten Einzelaufträgen nicht.'
        ]],
        ['4. Fachliche Qualität und Unabhängigkeit', [
            'Der Partner übernimmt nur Leistungen, für die er tatsächlich fachlich geeignet und gegebenenfalls gesetzlich berechtigt ist. Er legt seine einschlägigen Qualifikationen und, soweit für die Tätigkeit erforderlich, Zulassungen sowie bestehenden Versicherungsschutz zutreffend offen. Wegfall oder wesentliche Änderung dieser Voraussetzungen und erkennbare Interessenkonflikte werden unverzüglich mitgeteilt. Nicht vorhandene Zertifizierungen, öffentliche Bestellungen oder Befugnisse dürfen weder behauptet noch durch die Netzwerkaufnahme suggeriert werden.',
            'Aufträge sind sorgfältig, fachlich unabhängig, nachvollziehbar dokumentiert und innerhalb der mit dem Auftraggeber vereinbarten Fristen zu erledigen. Befund, Beurteilung und Empfehlung dürfen nicht von einem gewünschten Ergebnis, einer Provisionszahlung oder wirtschaftlichen Interessen abhängig gemacht werden. Berufsrechtliche und gesetzliche Vergütungs- oder Provisionsverbote gehen vor. Ist die konkrete Provision danach unzulässig, wird der Auftrag nicht unter einer unzulässigen Provisionsvereinbarung weitergegeben.',
            'Der Partner wahrt die Vertraulichkeit und die berechtigten Interessen des Auftraggebers und tritt im Zusammenhang mit dem Netzwerk sachlich und zuverlässig auf. Er informiert bei absehbaren Verzögerungen und stimmt erforderliche Änderungen rechtzeitig mit seinem Auftraggeber ab. Eine Unterbeauftragung richtet sich nach dem konkreten Kundenvertrag und den gesetzlichen Vorgaben; sie darf Qualifikation, Datenschutz und vereinbarte Leistung nicht beeinträchtigen.'
        ]],
        ['5. Expertenprofil und Kennzeichen', [
            'Ein Expertenprofil wird nur nach gesonderter inhaltlicher Freigabe veröffentlicht. Freigegeben werden können Name und Firma, tatsächlich nachgewiesene Qualifikationen, Fachgebiete, Einsatzgebiet, berufliche Kontaktdaten, Website, eine Kurzvorstellung und ein freigegebenes Porträt. Die Aufnahme allein ist keine Zustimmung zur Veröffentlichung sämtlicher eingereichter Unterlagen.',
            'Der Partner stellt nur Texte und Bilder bereit, die hierfür verwendet werden dürfen, und benennt erforderliche Urheberangaben. Er räumt dem Netzwerkbetreiber für die ausdrücklich freigegebenen Inhalte ein einfaches, nicht ausschließliches Nutzungsrecht zur Darstellung und technischen Anpassung des Expertenprofils auf sv-netzwerk.eu für die Dauer der Zusammenarbeit ein; eine weitergehende Werbenutzung bedarf gesonderter Zustimmung. Personenbildnisse werden nur mit wirksamer gesonderter Freigabe veröffentlicht. Nach Beendigung wird das öffentliche Profil ohne unnötige Verzögerung entfernt; rechtmäßig erforderliche Archivierung bleibt möglich.',
            'Die Bezeichnung SV-Netzwerk und seine Logos dürfen vom Partner nur nach vorheriger Freigabe und innerhalb des freigegebenen Umfangs verwendet werden. Es darf kein Eindruck einer Vertretungsmacht, eines Beschäftigungsverhältnisses oder einer vom Netzwerk garantierten fachlichen Zulassung entstehen.'
        ]],
        ['6. Vertraulichkeit und Datenschutz', [
            'Nicht öffentliche Kunden-, Schaden-, Geschäfts- und Vertragsinformationen dürfen nur für den vereinbarten Zweck verwendet und nur berechtigten Personen zugänglich gemacht werden. Die Verpflichtung gilt auch nach Vertragsende. Ausgenommen sind rechtmäßig allgemein bekannte Informationen, rechtmäßig unabhängig erlangte Kenntnisse und gesetzlich vorgeschriebene Offenlegungen. Bei einer Datenschutz- oder Vertraulichkeitsverletzung informiert die betroffene Partei die andere unverzüglich, soweit deren Daten oder Pflichten berührt sind.',
            'Jede Partei prüft vor einer Datenweitergabe deren Rechtsgrundlage und beschränkt sie auf die für den konkreten Auftrag erforderlichen Angaben. Vor einer Auftragsannahme genügen grundsätzlich anonymisierte oder auf das Erforderliche begrenzte Informationen. Besondere Kategorien personenbezogener Daten, etwa Gesundheitsdaten, werden nur bei gesondert tragfähiger Rechtsgrundlage übermittelt. Der Partner nutzt Kontaktdaten nicht für sachfremde Werbung.',
            'Soweit die Parteien jeweils eigene Zwecke und Mittel der Verarbeitung bestimmen, handeln sie als eigenständige Verantwortliche. Ergibt die konkrete Leistung eine Auftragsverarbeitung oder gemeinsame Verantwortlichkeit, ist vor Beginn die erforderliche Vereinbarung nach Artikel 28 beziehungsweise 26 DSGVO zu schließen. Dieser Rahmenvertrag ersetzt sie nicht. Gesetzliche Informations-, Sicherheits-, Lösch- und Meldepflichten bleiben bestehen.',
            'Der im Vertragskopf benannte Netzwerkbetreiber verarbeitet die beruflichen Kontakt-, Qualifikations-, Vertrags- und Abrechnungsdaten zur Anbahnung und Durchführung dieser Zusammenarbeit, zur Erfüllung gesetzlicher Pflichten und zum Schutz berechtigter rechtlicher Interessen auf Grundlage von Artikel 6 Absatz 1 Buchstaben b, c und f DSGVO, soweit jeweils anwendbar. Empfänger sind nur erforderliche und berechtigte Mitarbeiter, gebundene IT-Dienstleister, Steuer- und Rechtsberater sowie bei rechtlicher Verpflichtung zuständige Stellen. Eine öffentliche Profilanzeige erfolgt ausschließlich im gesondert freigegebenen Umfang. Eine Übermittlung in Drittstaaten ist mit diesem Vertrag nicht vereinbart.',
            'Daten werden für die Zusammenarbeit und anschließend nur so lange gespeichert, wie gesetzliche Aufbewahrungspflichten oder die Abwehr beziehungsweise Durchsetzung konkreter Ansprüche dies erfordern; danach werden sie gelöscht. Betroffene können sich über die im Vertragskopf genannte Kontaktadresse an den Netzwerkbetreiber wenden. Es bestehen nach den gesetzlichen Voraussetzungen Rechte auf Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit sowie Widerspruch gegen eine Verarbeitung aus berechtigten Interessen. Eine gesonderte Einwilligung kann für die Zukunft widerrufen werden. Eine Beschwerde bei einer Datenschutzaufsichtsbehörde bleibt möglich. Die für den Vertrag notwendigen Angaben sind für Abschluss und Abwicklung erforderlich; ohne sie kann die Zusammenarbeit nicht durchgeführt werden. Es findet keine automatisierte Entscheidung einschließlich Profiling über die Partneraufnahme statt.'
        ]],
        ['7. Verantwortung und Haftung', [
            'Für seine Fachleistung und die ihm obliegenden Kundenpflichten ist der jeweilige Auftragnehmer verantwortlich. Der Netzwerkbetreiber übernimmt durch eine bloße Anfrageweitergabe weder die fachliche Leistung noch eine Garantie für Bonität, Zahlung oder Ergebnis des Kundenauftrags. Eigene Pflichten des Netzwerkbetreibers bleiben unberührt. Beide Parteien haften nach den gesetzlichen Vorschriften; insbesondere wird keine Haftung für Vorsatz, grobe Fahrlässigkeit oder Schäden an Leben, Körper oder Gesundheit ausgeschlossen.'
        ]],
        ['8. Laufzeit und Beendigung', [
            'Der Vertrag beginnt mit Annahme durch beide Parteien und läuft auf unbestimmte Zeit. Jede Partei kann ihn mit einer Frist von 14 Kalendertagen in Textform beenden. Das Recht zur außerordentlichen Kündigung aus wichtigem Grund bleibt bestehen. Bereits geschlossene Kundenverträge und ausdrücklich bestätigte Einzelaufträge sind nach ihren eigenen Bedingungen abzuwickeln. Neue Verpflichtungen entstehen nach Vertragsende nicht automatisch. Vertraulichkeit, notwendige Datenaufbewahrung und bereits begründete Abrechnungsansprüche bleiben im erforderlichen Umfang bestehen.'
        ]],
        ['9. Schlussbestimmungen und spätere Gesellschaft', [
            'Es gilt deutsches Recht. Die gesetzlichen Gerichtsstände bleiben bestehen. Individuell getroffene Vereinbarungen haben Vorrang. Änderungen dieses Rahmenvertrags und Einzelvereinbarungen sollen aus Nachweisgründen in Textform festgehalten werden; der Vorrang tatsächlich getroffener Individualvereinbarungen wird dadurch nicht eingeschränkt. Bei Unwirksamkeit einzelner Bestimmungen gelten die gesetzlichen Regelungen; eine automatische Ersetzung durch eine wirtschaftlich möglichst ähnliche Klausel wird nicht vereinbart.',
            'Eine künftig gegründete Gesellschaft, insbesondere eine UG (haftungsbeschränkt), wird nicht automatisch Vertragspartner. Eine Übernahme dieses Vertrags oder bestehender Einzelaufträge durch eine andere Rechtsperson bedarf einer gesonderten Vereinbarung mit ausdrücklicher Zustimmung des Partners und der beteiligten Rechtsträger. Bis zu einer wirksamen Übernahme bleibt der im Vertragskopf bezeichnete Netzwerkbetreiber verantwortlich.'
        ]],
    ];
}
