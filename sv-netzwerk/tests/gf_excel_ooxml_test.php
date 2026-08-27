<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/intern/api/gf-excel-ooxml.php';

$namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$input = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<workbook xmlns="'.$namespace.'"><bookViews/><sheets/>'
    .'<extLst><ext uri="test"/></extLst></workbook>';
$result = gfExcelWorkbookFullCalcXml($input);
gfExcelAssertWorkbookCalcOrder($result);

$dom = new DOMDocument();
if (!@$dom->loadXML($result)) {
    fwrite(STDERR, "Erzeugte workbook.xml ist nicht wohlgeformt.\n");
    exit(1);
}
$xp = new DOMXPath($dom);
$xp->registerNamespace('x', $namespace);
$children = [];
foreach ($xp->query('/x:workbook/*') ?: [] as $child) $children[] = $child->localName;
$calcIndex = array_search('calcPr', $children, true);
$extIndex = array_search('extLst', $children, true);
if ($calcIndex === false || $extIndex === false || $calcIndex >= $extIndex) {
    fwrite(STDERR, 'Ungültige OOXML-Reihenfolge: '.implode(',', $children)."\n");
    exit(1);
}
$calcPr = $xp->query('/x:workbook/x:calcPr')?->item(0);
foreach (['calcId'=>'0','fullCalcOnLoad'=>'1','forceFullCalc'=>'1','calcMode'=>'auto'] as $name=>$expected) {
    if (!$calcPr instanceof DOMElement || $calcPr->getAttribute($name) !== $expected) {
        fwrite(STDERR, "Fehlendes Neuberechnungsattribut: {$name}\n");
        exit(1);
    }
}

$brokenOrder = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<workbook xmlns="'.$namespace.'"><sheets/><extLst/>'
    .'<calcPr calcId="0"/></workbook>';
$rejected = false;
try {
    gfExcelAssertWorkbookCalcOrder($brokenOrder);
} catch (RuntimeException) {
    $rejected = true;
}
if (!$rejected) {
    fwrite(STDERR, "Die bisherige beschädigte Elementreihenfolge wurde nicht gesperrt.\n");
    exit(1);
}

echo "XLSM-workbook.xml: calcPr steht schema-konform vor extLst.\n";
