from pathlib import Path

root = Path(__file__).resolve().parents[1]
path = root / 'public/intern/api/gf-ai-generate.php'
text = path.read_text(encoding='utf-8')

old = 'Formuliere die Reserve neutral als fachliches Ergebnis. Entferne veraltete Nullansätze und grobe Kostenschwellen, wenn aktuelle konkrete Beträge vorliegen.'
new = ('Formuliere die Reserve neutral als fachliches Ergebnis. Die Reserve ist eine belastbare Gesamtschätzung der voraussichtlichen versicherten Schadenaufwendungen und darf niemals willkürlich unter bereits konkret belegten Kostenansätzen liegen. '
       'Vor Festsetzung der Reserve sind sämtliche vorhandenen Rechnungen, Kostenvoranschläge, Angebote, bereits ausgeführten aber noch nicht abgerechneten Leistungen sowie erkennbare offene Folgekosten zu berücksichtigen. '
       'Nicht überlappende bekannte Bruttobeträge sind zusammenzurechnen. Überlappen Rechnung und KVA denselben Leistungsumfang, darf nicht doppelt gerechnet werden; dann ist der noch offene beziehungsweise höhere Gesamtaufwand nachvollziehbar anzusetzen. '
       'Die Reserve darf jedenfalls nicht niedriger sein als der höchste einzelne belegte versicherte KVA-/Angebotsbetrag und bei mehreren voneinander unabhängigen Kostenblöcken nicht niedriger als deren Summe. '
       'Bereits ausgeführte, aber noch nicht abgerechnete Leistungen sind mit dem vorliegenden KVA oder einem sonst belastbaren Ansatz in der Reserve zu berücksichtigen. '
       'Ein Selbstbehalt wird bei der Schadenreserve nicht von den voraussichtlichen Bruttoschadenkosten abgezogen, sofern nicht ausdrücklich eine Entschädigungsreserve nach Selbstbehalt verlangt wird. '
       'Bestehen noch unbekannte Folgekosten, ist zusätzlich ein angemessener nachvollziehbarer Puffer zu berücksichtigen; keine pauschale Fantasiezahl und keine Reserve unter dem bekannten Kostenstand. '
       'Entferne veraltete Nullansätze und grobe Kostenschwellen, wenn aktuelle konkrete Beträge vorliegen.')
if old not in text:
    raise SystemExit('general reserve instruction anchor not found')
text = text.replace(old, new, 1)

anchor = "$generalHasKva=preg_match('/\\b(?:KVA|Kostenvoranschlag|Angebot)\\b/ui',implode(' ',array_map('strval',$sourceNames)))===1;if($generalHasKva&&(!preg_match('/\\b(?:EUR|€)\\b/u',$generalKalkulation)||preg_match('/\\b(?:kein|keine|nicht)\\b[^.]{0,80}\\b(?:KVA|Kostenvoranschlag|Angebot)\\b/ui',$generalKalkulation)))$generalFaults[]='der vorhandene Kostenvoranschlag beziehungsweise das Angebot wurde nicht vollständig mit Hauptpositionen sowie Netto-, Umsatzsteuer- und Bruttobetrag in der Kalkulation ausgewertet';"
insert = anchor + "$generalReserveAmount=null;if(preg_match('/\\b(?:Schadenreserve|Reserve)\\b[^0-9]{0,80}([0-9]{1,3}(?:\\.[0-9]{3})*(?:,[0-9]{2})|[0-9]+(?:,[0-9]{2})?)\\s*(?:EUR|€)/ui',$generalKalkulation,$generalReserveMatch))$generalReserveAmount=(float)str_replace(['. ','.',','],['','', '.'],$generalReserveMatch[1]);$generalKnownGross=[];if(preg_match_all('/\\b(?:Rechnung|KVA|Kostenvoranschlag|Angebot)\\b[^.]{0,180}?([0-9]{1,3}(?:\\.[0-9]{3})*(?:,[0-9]{2})|[0-9]+(?:,[0-9]{2})?)\\s*(?:EUR|€)\\s*(?:brutto)?/ui',$generalKalkulation,$generalCostMatches))foreach(($generalCostMatches[1]??[])as$generalCostValue){$generalCost=(float)str_replace(['. ','.',','],['','', '.'],(string)$generalCostValue);if($generalCost>0)$generalKnownGross[]=$generalCost;}if($generalReserveAmount!==null&&$generalKnownGross&&$generalReserveAmount+0.01<max($generalKnownGross))$generalFaults[]='die Schadenreserve liegt unter einem bereits konkret belegten KVA-/Angebots- oder Rechnungsbetrag; Reserve mindestens auf den bekannten versicherten Kostenstand anheben und voneinander unabhängige Kostenblöcke zusätzlich zusammenführen';"
if anchor not in text:
    raise SystemExit('general reserve QC anchor not found')
text = text.replace(anchor, insert, 1)

path.write_text(text, encoding='utf-8')
print('General report reserve plausibility rules added.')
