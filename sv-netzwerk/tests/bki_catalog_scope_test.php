<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/bki-batch.php';
require_once __DIR__.'/../public/intern/api/bki-catalog-calculation.php';
$component=['description'=>'Gussrohrleitung demontieren, bis DN100','scope'=>'Durchmesser: DN50 bis DN100','source_quote'=>'Gussrohrleitung'];
$row=['description'=>'Rückbau alte Gussfallleitung','scope'=>'12 m, alte Befestigungen entfernen'];
if(bkCatalogScopeIssue($component,$row,[])==='')throw new RuntimeException('Unbekannter Alt-DN wurde angenommen.');
if(bkCatalogScopeIssue($component,$row,['notes'=>'Planungsannahme Gussleitung DN150'])==='')throw new RuntimeException('Falsche DN-Variante angenommen.');
$component['scope']='Durchmesser: DN125 bis DN200';$component['description']='Gussrohrleitung demontieren, bis DN200';
if(bkCatalogScopeIssue($component,$row,['notes'=>'Planungsannahme Gussleitung DN150'])!=='')throw new RuntimeException('Belegte Planungsvariante fehlt.');
echo "Old-pipe dimensions remain separate from replacement dimensions.\n";
$parts=[['id'=>'packet','source_action'=>'Herstellen','gross_prices'=>[85,92,109],'quantity_verified'=>true,'scope_issue'=>'','unit'=>'m','quantity'=>12,'description'=>'Abwasser HT-Rohrleitungen DN/OD110 Formteile','position_code'=>'411.10/05'],['id'=>'pipe','quantity_verified'=>true,'scope_issue'=>'','unit'=>'m','quantity'=>12,'description'=>'Abwasserleitung HT-Rohr DN/OD110']];
$parts=bkCatalogRemoveOverlap($parts);if($parts[1]['scope_issue']==='')throw new RuntimeException('Paket und Einzelrohr wurden doppelt angesetzt.');
echo "Assembly prices exclude duplicate constituent pipe prices.\n";
