<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/technical-release-pdf-core.php';

$net=2639.00;$vat=round($net*.19,2);$gross=round($net+$vat,2);
$positions=[
 ['position_no'=>'1','description'=>'Baustelleneinrichtung','quantity'=>1,'unit'=>'pauschal','unit_price'=>250,'line_total'=>250],
 ['position_no'=>'2','description'=>'Pflaster entfernen, säubern und seitlich lagern','quantity'=>16,'unit'=>'qm','unit_price'=>32.5,'line_total'=>520],
 ['position_no'=>'3','description'=>'Sand entfernen und entsorgen','quantity'=>1,'unit'=>'pauschal','unit_price'=>285,'line_total'=>285],
 ['position_no'=>'4','description'=>'Erde abgraben, laden und entsorgen','quantity'=>2.5,'unit'=>'cbm','unit_price'=>145,'line_total'=>362.5],
 ['position_no'=>'5','description'=>'Schotter liefern, planieren und verdichten','quantity'=>3,'unit'=>'cbm','unit_price'=>115,'line_total'=>345],
 ['position_no'=>'6','description'=>'Splitt liefern, verteilen und abziehen','quantity'=>16,'unit'=>'qm','unit_price'=>15.5,'line_total'=>248],
 ['position_no'=>'7','description'=>'Pflaster verlegen und abrütteln','quantity'=>16,'unit'=>'qm','unit_price'=>28.5,'line_total'=>456],
 ['position_no'=>'8','description'=>'Quarzsand','quantity'=>3,'unit'=>'Sack','unit_price'=>7.5,'line_total'=>22.5],
 ['position_no'=>'9','description'=>'Regale abbauen, seitlich lagern und wieder stellen','quantity'=>1,'unit'=>'pauschal','unit_price'=>150,'line_total'=>150],
];
$pdf=trBuildTechnicalReleasePdf(['meta'=>['case_no'=>'TEST-26','vn'=>'Klaus Droxler','location'=>'Törlestraße 3, 76646 Bruchsal','insurer'=>'Testversicherung','regulator'=>'Christian Wächter','decision'=>'Freigabe mit Vorbehalt','assessment'=>'Die angebotenen Arbeiten sind rechnerisch nachvollziehbar.','delimitation'=>'Schadenbezug ist anhand der Fallakte abschließend zu bestätigen.','date'=>'2026-09-07'],'quotes'=>[['source'=>'Angebot 03.09.2026.pdf','company'=>'Babic GmbH','quote_number'=>'2026-29b','quote_date'=>'2026-09-03','net_total'=>$net,'vat_rate'=>19,'vat_total'=>$vat,'gross_total'=>$gross,'technical_assessment'=>'Die Positionen ergeben die ausgewiesene Nettosumme.','positions'=>$positions]]]);
if(!str_starts_with($pdf,'%PDF-1.4'))throw new RuntimeException('Keine gültige PDF-Ausgabe.');
foreach(['Technische Freigabe','2026-29b','2.639,00 EUR','501,41 EUR','3.140,41 EUR','Baustelleneinrichtung']as$needle)if(!str_contains($pdf,$needle))throw new RuntimeException('PDF-Inhalt fehlt: '.$needle);
if(isset($argv[1]))file_put_contents($argv[1],$pdf);
echo "Technische Freigabe: PDF-Vorlage, Summen und Detailpositionen geprüft.\n";
