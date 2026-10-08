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
