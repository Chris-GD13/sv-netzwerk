<?php
declare(strict_types=1);

/**
 * Aktiviert die vollständige Neuberechnung, ohne die vorgeschriebene
 * Elementreihenfolge in xl/workbook.xml zu verletzen. calcPr muss vor extLst
 * stehen; ein Anhängen unmittelbar vor </workbook> beschädigt moderne XLSM.
 */
function gfExcelWorkbookFullCalcXml(string $xml): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    if (!@$dom->loadXML($xml)) {
        throw new RuntimeException('Excel-Arbeitsmappe enthält eine ungültige workbook.xml.');
    }

    $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('x', $namespace);
    $workbook = $xp->query('/x:workbook')?->item(0);
    if (!$workbook instanceof DOMElement) {
        throw new RuntimeException('Excel-Arbeitsmappe enthält kein workbook-Element.');
    }

    $calcPr = $xp->query('/x:workbook/x:calcPr')?->item(0);
    if (!$calcPr instanceof DOMElement) {
        $calcPr = $dom->createElementNS($namespace, 'calcPr');
        $extLst = $xp->query('/x:workbook/x:extLst')?->item(0);
        if ($extLst instanceof DOMElement) $workbook->insertBefore($calcPr, $extLst);
        else $workbook->appendChild($calcPr);
    }
    $calcPr->setAttribute('calcId', '0');
    $calcPr->setAttribute('fullCalcOnLoad', '1');
    $calcPr->setAttribute('forceFullCalc', '1');
    $calcPr->setAttribute('calcMode', 'auto');

    $saved = $dom->saveXML();
    if (!is_string($saved) || $saved === '') {
        throw new RuntimeException('Excel-Arbeitsmappe konnte nicht gespeichert werden.');
    }
    return $saved;
}

function gfExcelAssertWorkbookCalcOrder(string $xml): void
{
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        throw new RuntimeException('Excel-QS-Sperre: workbook.xml ist nicht wohlgeformt.');
    }
    $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('x', $namespace);
    $children = [];
    foreach ($xp->query('/x:workbook/*') ?: [] as $child) $children[] = $child->localName;
    $calcIndex = array_search('calcPr', $children, true);
    $extIndex = array_search('extLst', $children, true);
    if ($calcIndex === false) {
        throw new RuntimeException('Excel-QS-Sperre: Neuberechnungseinstellung calcPr fehlt.');
    }
    if ($extIndex !== false && $calcIndex >= $extIndex) {
        throw new RuntimeException('Excel-QS-Sperre: calcPr steht hinter extLst und würde die XLSM-Datei beschädigen.');
    }
}
