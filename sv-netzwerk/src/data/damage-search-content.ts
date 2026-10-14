export interface DamageSearchContent { h1: string; title: string; description: string; intro: string; insuredTitle: string; insured: string; insurerTitle: string; insurer: string; faqQuestion: string; faqAnswer: string; experts: string[]; articles: string[]; }

export const damageSearchContent: Record<string, DamageSearchContent> = {
  "brand": {
    "h1": "Brandschaden-Gutachter für Gebäude und Hausrat",
    "title": "Brandschaden Gutachter: Rauch, Ruß und Löschwasser",
    "description": "Brandschäden an Gebäude und Hausrat: Ursache, Rauch-, Ruß- und Löschwasserfolgen sowie Sanierungsumfang durch Sachverständige bewerten lassen.",
    "intro": "Nach einem Brand sind sichtbare Zerstörungen nur ein Teil der Schadenaufnahme. Rauch, Ruß und Löschwasser können weitere Räume, Bauteile und bewegliche Sachen betreffen. Wir dokumentieren die Einwirkung und unterscheiden Reinigung, Reparatur und erforderlichen Ersatz.",
    "insuredTitle": "Für Versicherungsnehmer",
    "insured": "Fotos, betroffene Räume und Gegenstände, den zeitlichen Ablauf sowie vorhandene Sanierungsangebote zusammenstellen. Eine Untersuchung ist erst möglich, wenn der betroffene Bereich sicher zugänglich ist. Der Gutachtenauftrag wird anhand der konkreten Schadenfrage abgestimmt.",
    "insurerTitle": "Für Versicherer",
    "insurer": "Brandbedingte Schäden, Vorzustand, Rückbau, Reinigung und Wiederherstellung nachvollziehbar zuordnen. Bei umfangreichen Schäden werden Fachzuständigkeiten, Kostenprüfung und Reserveentwicklung im vereinbarten Auftrag zusammengeführt.",
    "faqQuestion": "Welche Folgeschäden werden aufgenommen?",
    "faqAnswer": "Neben den unmittelbar verbrannten Bauteilen werden dokumentierte Rauch-, Ruß-, Löschwasser- und Öffnungsschäden berücksichtigt. Art und Umfang der Untersuchung richten sich nach Einwirkung, Bauteilaufbau und Prüfziel.",
    "experts": [
      "christian-waechter",
      "carmen-gohl"
    ],
    "articles": [
      "rueckbau-brandschaden-fachlich-begrenzen-dokumentieren",
      "elektrische-brandursachen-lichtbogen-kurzschluss-thermische-ueberlastung"
    ]
  },
  "leitungswasser": {
    "h1": "Gutachter für Wasserschaden und Rohrbruch",
    "title": "Wasserschaden Gutachter: Rohrbruch und Leitungswasser",
    "description": "Wasserschaden und Rohrbruch fachlich prüfen: Ursache, Feuchteverteilung, Trocknung, Rückbau und Kosten für Versicherungsnehmer und Versicherer bewerten.",
    "intro": "Bei einem Wasserschaden in Wohnung oder Gebäude müssen Austrittsstelle, Wasserweg und betroffene Aufbauten zusammenpassen. Wir bewerten die vorliegenden Befunde und grenzen Trocknung, Rückbau, Reparatur und Wiederherstellung bauteilbezogen ab.",
    "insuredTitle": "Für Versicherungsnehmer",
    "insured": "Schadenverlauf, Fotos, Leckageberichte, Feuchtemessungen und Angebote sichern. Beschädigte Bauteile und Gegenstände vor Veränderungen dokumentieren, soweit das gefahrlos möglich ist. Offene Fragen zum Schadenumfang können als eigener Prüfauftrag untersucht werden.",
    "insurerTitle": "Für Versicherer",
    "insurer": "Leckageortung, Suchöffnungen, Trocknung und Sanierung nach Befund und Schadenbezug prüfen. Mengen, Leistungsüberschneidungen, Nachträge und Rechnungen werden dem festgestellten Wiederherstellungsumfang zugeordnet.",
    "faqQuestion": "Muss nach einem Wasserschaden alles entfernt werden?",
    "faqAnswer": "Der Rückbauumfang ergibt sich aus Material, Aufbau, Durchfeuchtung und dokumentierter Reparatur- oder Trocknungsfähigkeit. Eine pauschale Entfernung aller Bauteile lässt sich daraus nicht ableiten; offene Bereiche benötigen gezielte Untersuchung.",
    "experts": [
      "christian-waechter",
      "carmen-gohl"
    ],
    "articles": [
      "leitungswasserschaden-leckageortung-pruefstrategie",
      "technische-trocknung-leitungswasserschaeden-messkonzept-rueckbau-erfolgskontrolle",
      "kontrollierter-rueckbau-bei-leitungswasserschaeden"
    ]
  },
  "sturm": {
    "h1": "Gutachter für Sturm- und Hagelschäden",
    "title": "Sturmschaden und Hagelschaden: Gutachter für Gebäude",
    "description": "Sturm- und Hagelschäden an Dach, Fassade, Fenstern und Oberflächen prüfen lassen. Einwirkung, Vorschäden, Reparaturgrenzen und Wiederherstellungskosten bewerten.",
    "intro": "Gelöste Dachbauteile, beschädigte Fenster oder Hagelspuren benötigen eine bauteilbezogene Bewertung. Wir erfassen die Schäden und prüfen, welche Einwirkung zum dokumentierten Zustand passt und welche Bereiche repariert oder ersetzt werden müssen.",
    "insuredTitle": "Für Versicherungsnehmer",
    "insured": "Fotos vom Schaden und vom Zustand vor dem Ereignis, Datum, Ort und bereits durchgeführte Sicherungsmaßnahmen bereitstellen. Dach, Fenster und Fassade getrennt dokumentieren; die Besichtigung gefährlicher Bereiche bleibt den zuständigen Fachkräften vorbehalten.",
    "insurerTitle": "Für Versicherer",
    "insurer": "Ereigniswirkung von Alterung, Befestigungszustand und vorhandenen Schäden abgrenzen. Erforderliche Leistungen, Flächen und Kosten nachvollziehbar herleiten. Bei vielen Schäden aus einem Ereignis unterstützt die gemeinsame Dokumentation das Kumulschadenmanagement.",
    "faqQuestion": "Wie werden Hagelspuren und Reparaturgrenzen bewertet?",
    "faqAnswer": "Material, Funktion, Lage und Ausdehnung der Spuren werden zusammen betrachtet. Die Bewertung unterscheidet die konkrete Beschädigung von bereits vorhandenen Veränderungen und begründet den erforderlichen Reparatur- oder Ersatzumfang.",
    "experts": [
      "christian-waechter",
      "carmen-gohl",
      "marc-schuett",
      "claudius-freiberg"
    ],
    "articles": [
      "sturm-hagel-serienschaeden-prueffolge-2026-07-21-morning",
      "fenster-glasschaden-verglasung-reparaturgrenze"
    ]
  },
  "elementar": {
    "h1": "Gutachter für Starkregen, Überschwemmung und Rückstau",
    "title": "Elementarschaden Gutachter: Starkregen und Rückstau",
    "description": "Schäden durch Starkregen, Überschwemmung und Rückstau bewerten: Eintrittsweg, betroffene Räume, Kontamination und Wiederherstellung nachvollziehbar dokumentieren.",
    "intro": "Wasser im Keller kann unterschiedliche Eintrittswege haben. Bei Starkregen, Überschwemmung und Rückstau dokumentieren wir Wasserstand, Einwirkungsweg, betroffene Konstruktionen und vorhandene Befunde. Die technische Ursache bleibt von der vertraglichen Deckungsprüfung getrennt.",
    "insuredTitle": "Für Versicherungsnehmer",
    "insured": "Ereigniszeit, Wasserstand, Eintrittsstellen, betroffene Räume und Gegenstände sowie vorhandene Fotos zusammenführen. Reinigung, Trocknung und Wiederherstellung werden anhand des tatsächlichen Zustands geprüft. Die Anfrage sollte den Schadenort und die konkrete Frage enthalten.",
    "insurerTitle": "Für Versicherer",
    "insurer": "Eintrittsweg, Schadenbereiche und erforderliche Maßnahmen getrennt ausweisen. Bei regionalen Ereignissen können priorisierte Besichtigungen, einheitliche Akten und abgestimmte Fachzuständigkeiten die Bearbeitung strukturieren.",
    "faqQuestion": "Sind Starkregen, Rückstau und Grundwasser dasselbe?",
    "faqAnswer": "Diese Begriffe beschreiben unterschiedliche technische Zusammenhänge. Für eine belastbare Bewertung müssen der tatsächliche Eintrittsweg und die Einwirkung festgestellt werden. Welche Gefahr versichert ist, wird anhand des jeweiligen Vertrags geprüft.",
    "experts": [
      "christian-waechter",
      "carmen-gohl"
    ],
    "articles": [
      "starkregen-rueckstau-eintrittswege-rueckstauebene-schadenbereiche",
      "starkregen-rueckstau-schadenaufnahme-regulierung"
    ]
  },
  "schimmel": {
    "h1": "Gutachter für Feuchte- und Schimmelschäden",
    "title": "Schimmel Gutachter: Feuchteursache und Sanierung prüfen",
    "description": "Feuchte- und Schimmelschäden untersuchen lassen: Ursache, betroffene Materialien und Sanierungsumfang nachvollziehbar bewerten und Erfolgskontrolle abstimmen.",
    "intro": "Schimmelbefall und seine Feuchteursache müssen getrennt untersucht werden. Wir ordnen Materialzustand, Feuchteverteilung und vorliegende Untersuchungen ein. Ob der Schaden mit einem Versicherungsereignis zusammenhängt, bleibt eine fallbezogene Prüffrage.",
    "insuredTitle": "Für Versicherungsnehmer",
    "insured": "Zeitlichen Verlauf, Fotos, Messungen, frühere Wasserschäden und bereits ausgeführte Arbeiten angeben. Der Prüfauftrag kann Ursachenklärung, Umfang der Sanierung oder die Bewertung vorhandener Sanierungsunterlagen betreffen.",
    "insurerTitle": "Für Versicherer",
    "insurer": "Ereignisbezug, Vorzustand, Feuchteursache, Maßnahmen und Sanierungskontrolle getrennt bewerten. Erforderliche Fachuntersuchungen und Kosten sind auf den belegten Befund und den konkreten Sanierungsbereich zu beziehen.",
    "faqQuestion": "Was wird bei der Sanierungskontrolle geprüft?",
    "faqAnswer": "Ursachenbeseitigung, betroffene Materialien, ausgeführte Maßnahmen und vereinbarte Erfolgskriterien werden nachvollziehbar abgeglichen. Ein äußerlich unauffälliger Zustand beantwortet nicht automatisch alle Fragen zu verdeckten Bereichen.",
    "experts": [
      "carmen-gohl",
      "christian-waechter"
    ],
    "articles": [
      "schimmelsanierung-feuchteursache-feinreinigung-sanierungserfolg-pruefen"
    ]
  },
  "haftpflicht": {
    "h1": "Gutachter für Haftpflicht- und Mietsachschäden",
    "title": "Haftpflichtschaden Gutachter: Mietsachschäden prüfen",
    "description": "Haftpflicht- und Mietsachschäden an Bauteilen und Oberflächen technisch bewerten. Verursachung, Vorzustand, Reparaturumfang und Kosten getrennt dokumentieren.",
    "intro": "Beschädigte Türen, Fenster, Böden oder fremde Bauteile können eine technische Bewertung erfordern. Wir dokumentieren Schadenbild und Vorzustand und beurteilen den nachvollziehbaren Wiederherstellungsumfang, ohne die rechtliche Haftungsprüfung vorwegzunehmen.",
    "insuredTitle": "Für Versicherungsnehmer und Geschädigte",
    "insured": "Beschädigten Gegenstand, Ereignis, Beteiligte, Fotos und vorhandene Reparaturangebote angeben. Vorschäden und frühere Reparaturen offenlegen. Ein Gutachten kann eine einzelne strittige technische Frage oder den Schadenumfang behandeln.",
    "insurerTitle": "Für Versicherer",
    "insurer": "Kausalitätskette, Ereignisschaden und bereits vorhandene Veränderungen getrennt prüfen. Reparaturfähigkeit, Mengen und Kosten schadenbezogen dokumentieren. Die Bearbeitung durch einen Regulierer erfolgt im erteilten Auftrag und Vollmachtsrahmen.",
    "faqQuestion": "Muss ein beschädigtes Bauteil vollständig ersetzt werden?",
    "faqAnswer": "Das hängt vom betroffenen Aufbau, dem Umfang der Beschädigung und den fachlich möglichen Reparaturverfahren ab. Ersatz und Reparatur werden begründet verglichen; eine rechtliche Ersatzpflicht folgt nicht allein aus dem technischen Befund.",
    "experts": [
      "holger-roth",
      "christian-waechter",
      "carmen-gohl"
    ],
    "articles": [
      "plausibilitaetspruefung-schadenbild-kausalitaet",
      "fenster-glasschaden-verglasung-reparaturgrenze"
    ]
  },
  "fenster-fassade": {
    "h1": "Gutachter für Fenster-, Glas-, Türen- und Fassadenschäden",
    "title": "Fenster und Glas: Gutachter für Schäden und Reparatur",
    "description": "Fenster-, Glas-, Türen- und Fassadenschäden beurteilen lassen: Verglasung, Rahmen, Beschläge, Anschlüsse und Oberflächen auf Reparaturfähigkeit prüfen.",
    "intro": "Bei Glasschäden, beschädigten Türen, undichten Fenstern oder Fassadenschäden ist der betroffene Teil genau zu bestimmen. Verglasung, Rahmen, Beschlag, Befestigung und Anschluss werden getrennt aufgenommen und bewertet.",
    "insuredTitle": "Für Eigentümer und Versicherungsnehmer",
    "insured": "Bauteil, Alter soweit bekannt, Ereignis und konkrete Beanstandung beschreiben. Fotos, Produktunterlagen und Angebote erleichtern die Zuordnung zum passenden Fachgebiet. Bei Einbruchschäden sind beschädigte Bestandteile und bereits erfolgte Sicherungen zu dokumentieren.",
    "insurerTitle": "Für Versicherer",
    "insurer": "Ereignisschaden von Montagezustand, Mangel und Instandhaltung abgrenzen. Eine Reparaturgrenze wird bauteilbezogen begründet. Material- und Oberflächenschäden können spezialisierte Sachverständige des Tischlerhandwerks erfordern.",
    "faqQuestion": "Kann nur das Glas oder ein einzelner Beschlag ersetzt werden?",
    "faqAnswer": "Die Antwort richtet sich nach Aufbau, Beschädigung und verfügbaren fachgerechten Reparaturmöglichkeiten. Der Schaden an einem Einzelteil belegt nicht automatisch, dass das gesamte Fenster oder die gesamte Tür ersetzt werden muss.",
    "experts": [
      "christian-waechter",
      "marc-schuett",
      "claudius-freiberg"
    ],
    "articles": [
      "fenster-glasschaden-verglasung-reparaturgrenze",
      "fensteranschluss-abdichtung-untergrund-fugenmass-materialwahl"
    ]
  },
  "technische-gebaeudeausruestung": {
    "h1": "Gutachterliche Prüfung von Schäden an Gebäudetechnik",
    "title": "Gebäudetechnik nach Wasser- und Brandschaden prüfen",
    "description": "Schäden an Gebäudetechnik fachlich zuordnen: Einwirkung, Systemgrenzen, erforderliche Fachprüfungen und Reparatur- oder Austauschumfang dokumentieren.",
    "intro": "Nach Wasser- oder Brandeinwirkung benötigen technische Anlagen eine gezielte Prüfung ihrer betroffenen Komponenten und Schutzfunktionen. Wir führen Schadenbefunde, Unterlagen und notwendige Fachzuständigkeiten in der technischen Schadenbewertung zusammen.",
    "insuredTitle": "Für Versicherungsnehmer",
    "insured": "Anlage, Einwirkung, Fehlermeldungen, Wartungsunterlagen und bereits durchgeführte Prüfungen angeben. Eine gutachterliche Schadenbewertung ersetzt keine Sicherheitsfreigabe oder Wiederinbetriebnahme durch die zuständige qualifizierte Fachstelle.",
    "insurerTitle": "Für Versicherer",
    "insurer": "Schadenbedingte Prüfung, Reparatur, Austausch und Wiederinbetriebnahme voneinander abgrenzen. Anlagenzustand, Wartung und Verbesserungen getrennt einordnen und die erforderlichen Leistungen den betroffenen Systemteilen zuordnen.",
    "faqQuestion": "Bedeutet ein störungsfreier Start, dass kein Schaden besteht?",
    "faqAnswer": "Eine Betriebsanzeige oder ein erfolgreicher Start allein beantwortet nicht alle Fragen zu Einwirkung und Schutzfunktionen. Welche Fachprüfung nötig ist, richtet sich nach Anlage, Ereignis und vorliegenden Befunden.",
    "experts": [
      "christian-waechter"
    ],
    "articles": [
      "waermepumpe-ueberflutung-pruefung-kostenabgrenzung",
      "wallbox-ladeinfrastruktur-ueberflutung-pruefung-kostenabgrenzung"
    ]
  },
  "gewerbe-industrie": {
    "h1": "Gutachter und Schadenregulierung für Gewerbe und Industrie",
    "title": "Gewerbe- und Industrieschäden: Gutachten und Regulierung",
    "description": "Gewerbe- und Industrieschäden koordinieren: Gebäude, Anlagen, Inventar, Maßnahmen und Kosten für nachvollziehbare Schadenentscheidungen zusammenführen.",
    "intro": "Ein Brand- oder Wasserschaden im Betrieb kann Gebäude, Anlagen, Waren und betriebliche Abläufe gleichzeitig betreffen. Wir strukturieren die technische Schadenaufnahme und die Abstimmung der beteiligten Fachdisziplinen.",
    "insuredTitle": "Für Unternehmen",
    "insured": "Betroffene Betriebsbereiche, Anlagen, Inventar und bereits getroffene Maßnahmen benennen. Unterlagen zum Vorzustand und zur Nutzung helfen, Schadenumfang und Wiederherstellung zu prüfen. Fragen zu Ausfallfolgen benötigen zusätzlich die jeweiligen Nachweise und den Vertragsbezug.",
    "insurerTitle": "Für Versicherer",
    "insurer": "Maßnahmenprioritäten, Wiederherstellungsumfang, Fachprüfungen und Kosten laufend dokumentieren. Groß- und Komplexschäden werden mit abgestimmten Zuständigkeiten, offenen Entscheidungen und Reservefortschreibung im erteilten Auftrag bearbeitet.",
    "faqQuestion": "Wie werden technische Schäden und betriebliche Ausfallfolgen getrennt?",
    "faqAnswer": "Zunächst werden die betroffenen Sachen und erforderlichen Wiederherstellungsmaßnahmen dokumentiert. Ausfallzeiträume und weitere Folgen benötigen eigene Nachweise. Die versicherungsvertragliche Einordnung wird gesondert geprüft.",
    "experts": [
      "christian-waechter",
      "carmen-gohl"
    ],
    "articles": [
      "prueffaehige-dokumentation",
      "schadenreserve-nachvollziehbar-fortschreiben"
    ]
  },
  "hausrat": {
    "h1": "Gutachter für Hausratschäden und beschädigtes Inventar",
    "title": "Hausratschaden Gutachter: Reinigung oder Ersatz prüfen",
    "description": "Beschädigten Hausrat nach Brand, Wasser oder anderen Ereignissen aufnehmen. Zustand, Schadenbezug, Reinigungsfähigkeit und Wiederherstellung bewerten.",
    "intro": "Nach Brand, Rauch oder Wasser sind Möbel und weitere bewegliche Sachen einzeln aufzunehmen. Wir dokumentieren Zustand und Schadenbezug und unterscheiden fachlich mögliche Reinigung, Reparatur und erforderlichen Ersatz.",
    "insuredTitle": "Für Versicherungsnehmer",
    "insured": "Beschädigte Sachen mit Fotos und vorhandenen Anschaffungsbelegen oder Produktangaben erfassen. Frühere Beschädigungen und bereits erfolgte Reinigungen angeben. Die Unterlagen werden dem konkreten Gegenstand und Ereignis zugeordnet.",
    "insurerTitle": "Für Versicherer",
    "insurer": "Schadenliste, Eigentumsnachweise, Zustand und Wiederherstellungsangebote abgleichen. Bei hochwertigen Möbeln oder Oberflächen können spezialisierte Fachleute zur Materialbewertung eingebunden werden. Vertragliche Bewertungsgrundlagen bleiben gesondert zu prüfen.",
    "faqQuestion": "Wie wird Reinigung gegenüber Ersatz bewertet?",
    "faqAnswer": "Material, Art der Einwirkung, Zustand und fachlich belegte Reinigungsmöglichkeiten sind maßgeblich für die technische Bewertung. Eine Kostenposition muss dem konkret betroffenen Gegenstand zugeordnet werden können.",
    "experts": [
      "christian-waechter",
      "carmen-gohl",
      "claudius-freiberg"
    ],
    "articles": [
      "geruchsschaden-ursache-sanierung-erfolgskontrolle",
      "plausibilitaetspruefung-schadenbild-kausalitaet"
    ]
  },
  "gebaeude": {
    "h1": "Sachverständige und Gutachter für Gebäudeschäden",
    "title": "Gebäudeschaden Gutachter: Ursache, Umfang und Kosten",
    "description": "Gebäudeschäden fachlich bewerten: Bauteile, Ursachen, Wiederherstellungsumfang und Kosten. Gutachten und technische Prüfung für Eigentümer und Versicherer.",
    "intro": "Gebäudeschäden betreffen häufig mehrere Aufbauten und Gewerke. Wir ordnen die Befunde nach Bauteil und Einwirkung, klären die technischen Fragen und führen den erforderlichen Maßnahmenumfang mit den zugehörigen Kosten zusammen.",
    "insuredTitle": "Für Eigentümer und Versicherungsnehmer",
    "insured": "Objekt, Schadenort, Ereignis, Fotos, Pläne und vorhandene Berichte angeben. Eine einzelne technische Streitfrage kann ebenso geprüft werden wie ein umfangreicher Schaden an mehreren Gebäudeteilen. Der Leistungsumfang wird vor der Untersuchung abgestimmt.",
    "insurerTitle": "Für Versicherer",
    "insurer": "Schadenfeststellung, Vorschadenabgrenzung, Reparaturfähigkeit und Kostenprüfung nachvollziehbar verbinden. Für Gebäudeanalytik, Sanierungsberatung und Nachtragsprüfung werden Fachkompetenzen anhand des Prüfauftrags eingebunden.",
    "faqQuestion": "Welche Unterlagen benötigt der Gutachter?",
    "faqAnswer": "Hilfreich sind Schadenbeschreibung, Fotos, vorhandene Pläne, Untersuchungsberichte, Kostenvoranschläge und Rechnungen. Weitere Nachweise richten sich nach betroffenem Bauteil, Ereignis und konkreter Fragestellung.",
    "experts": [
      "christian-waechter",
      "carmen-gohl",
      "lenna-maria-walczok"
    ],
    "articles": [
      "prueffaehige-dokumentation",
      "plausibilitaetspruefung-schadenbild-kausalitaet"
    ]
  }
};
