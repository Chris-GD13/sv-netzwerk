from pathlib import Path

path = Path(__file__).resolve().parents[1] / 'src/pages/intern/kalkulation/index.astro'
text = path.read_text(encoding='utf-8')

if 'data-bki-storm-index="1"' not in text:
    print('storm quick index not present yet')
    raise SystemExit(0)

MARKER = "l:'Fassade – Außenputz Hagelschäden ausbessern'"
if MARKER in text:
    print('facade/painter storm presets already present')
    raise SystemExit(0)

# Raffstore ist im BKI als Stueckposition erfasst, nicht als m2-Position.
text = text.replace(
    "{c:'Rollladen',l:'Raffstore / Außenjalousie',u:'m²',q:'Außenjalousie Raffstore Lamellen Aluminium'}",
    "{c:'Rollladen',l:'Raffstore / Außenjalousie',u:'St',q:'Außenjalousie Raffstore Lamellen Aluminium als Einzelanlage, BKI LB 330 Pos. 16 / 330.000.032'}"
)

anchor = "    {c:'Sonstiges',l:'Sektionaltor / Garagentor',u:'St',q:'Sektionaltor Garagentor außen komplett'}"
if anchor not in text:
    raise SystemExit('storm preset anchor not found')

extra = """    {c:'Sonstiges',l:'Sektionaltor / Garagentor',u:'St',q:'Sektionaltor Garagentor außen komplett',mode:'material_labor'},
    {c:'Fassade/Putz',l:'Fassade – Außenputz Hagelschäden ausbessern',u:'m²',q:'Außenputz in hagelgeschädigten Teilflächen entfernen, Unter- und Oberputz ausbessern, reinigen und Bauschutt entsorgen; BKI LB 323 Außenputz ausbessern Teilflächen 323.000.190'},
    {c:'Fassade/Putz',l:'Fassade – Außenputz größere Fläche erneuern',u:'m²',q:'Außenputz zweilagig abschlagen und entsorgen sowie Unter- und Oberputz gleichartig neu herstellen; BKI LB 323'},
    {c:'Fassade/Putz',l:'WDVS – Oberputz/Armierung Hagelschaden ausbessern',u:'m²',q:'Hagelschaden an Wärmedämmverbundsystem: geschädigte Putz- und Armierungsschichten lokal entfernen, Putzarmierung Glasfasergewebe und Oberputz systemgerecht wiederherstellen; Dämmplatten bleiben soweit unbeschädigt bestehen; BKI LB 323'},
    {c:'Fassade/Putz',l:'WDVS – Teilfläche komplett erneuern',u:'m²',q:'Wärmedämmverbundsystem in geschädigter Teilfläche vollständig aufnehmen und entsorgen und gleichartiges WDVS einschließlich Dämmstoff, Armierung und Oberputz wiederherstellen; BKI LB 323 WDVS'},
    {c:'Fassade/Putz',l:'Fassade – Riss/Schlagstelle schließen',u:'m',q:'Einzelriss oder lineare Schlagstelle im Außenputz fachgerecht schließen und Oberfläche angleichen; BKI LB 323 Außenputz Risssanierung'},
    {c:'Fassadenbekleidung',l:'Faserzement-/Eternitplatten Fassade erneuern',u:'m²',q:'Nicht asbesthaltige Faserzement-Fassadenplatten demontieren und entsorgen, gleichartige Faserzement-Tafeln auf vorhandener Unterkonstruktion neu montieren; Rückbau 338.000.021 und Fassadenbekleidung Faserzement-Tafeln LB 338'},
    {c:'Fassadenbekleidung',l:'Faserzement Stülpdeckung Fassade erneuern',u:'m²',q:'Nicht asbesthaltige Faserzement-Fassadenbekleidung in Stülpdeckung demontieren und entsorgen und gleichartige Stülpdeckung auf vorhandener Holz-Unterkonstruktion neu montieren; BKI LB 338'},
    {c:'Fassadenbekleidung',l:'Holzfassade / Stülpschalung erneuern',u:'m²',q:'Holz-Außenwandbekleidung demontieren und entsorgen und gleichartige Holz-Stülpschalung auf vorhandener Unterkonstruktion neu herstellen; BKI LB 338 Fassadenbekleidung Holz Stülpschalung'},
    {c:'Fassadenbekleidung',l:'Holzschindelfassade erneuern',u:'m²',q:'Holzschindel-Außenwandbekleidung demontieren und entsorgen und gleichartige Holzschindelbekleidung neu montieren; BKI LB 338'},
    {c:'Fassadenbekleidung',l:'Metall-/Wellblechfassade erneuern',u:'m²',q:'Metallische Fassadenbekleidung Wellblech demontieren und entsorgen und gleichartige Metall-Wellblechbekleidung auf vorhandener Unterkonstruktion neu montieren; BKI LB 338'},
    {c:'Maler',l:'Fassade reinigen + streichen',u:'m²',q:'Fassade reinigen, tragfähigen Untergrund vorbereiten und Fassadenbeschichtung vollständig erneuern; BKI LB 334 Maler- und Lackierarbeiten Beschichtungen'},
    {c:'Maler',l:'Fassade nur streichen',u:'m²',q:'Außenfassade auf tragfähigem mineralischem Untergrund mit geeigneter Fassadenbeschichtung grundieren sowie Zwischen- und Schlussbeschichtung erneuern; BKI LB 334'},
    {c:'Maler',l:'Holzfenster außen vorbereiten + streichen',u:'St',q:'Holzfenster außen: lose Beschichtung entfernen, anschleifen/spachteln soweit erforderlich, grundieren und vollständige Beschichtung erneuern; BKI 334.000.088 plus passende Holzfenster-Beschichtung'},
    {c:'Maler',l:'Holztür außen vorbereiten + streichen',u:'m²',q:'Außentür aus Holz: Altbeschichtung vorbereiten, Schadstellen spachteln/schleifen, grundieren sowie Zwischen- und Schlussbeschichtung erneuern; BKI LB 334'},
    {c:'Maler',l:'Holztür innen + Zarge streichen',u:'m²',q:'Holztür innen einschließlich Zarge/Futter/Bekleidung anschleifen und blätternde Teile entfernen, Grund-, Zwischen- und Schlussbeschichtung erneuern; BKI 334.000.166'},
    {c:'Maler',l:'Metalltür / Metallfläche außen streichen',u:'m²',q:'Metallfläche außen reinigen, Schadstellen entrosten, vorbereiten/grundieren sowie Deckbeschichtung erneuern; BKI LB 334'},
    {c:'Maler',l:'Holzfassade reinigen + streichen',u:'m²',q:'Holzfassade außen reinigen, anschleifen und grundieren sowie geeignete Beschichtung vollständig erneuern; BKI LB 334'},
    {c:'Tore',l:'Garagentor Schwingtor',u:'St',q:'Garagentor Schwingtor komplett erneuern einschließlich Demontage und Montage',mode:'material_labor'},
    {c:'Tore',l:'Sektionaltor manuell',u:'St',q:'Sektionaltor Garage manuell komplett erneuern einschließlich Demontage und Montage',mode:'material_labor'},
    {c:'Tore',l:'Sektionaltor elektrisch',u:'St',q:'Sektionaltor Garage elektrisch mit Antrieb komplett erneuern einschließlich Demontage und Montage',mode:'material_labor'},
    {c:'Tore',l:'Garagentor-/Sektionaltor Paneel einzeln',u:'St',q:'Einzelnes beschädigtes Paneel eines Garagen-Sektionaltores austauschen einschließlich Ausbau und Einbau',mode:'material_labor'}"""

text = text.replace(anchor, extra, 1)
path.write_text(text, encoding='utf-8')
print('BKI storm facade/painter presets added')
