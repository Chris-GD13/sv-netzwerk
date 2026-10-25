<?php
declare(strict_types=1);
require __DIR__.'/../public/intern/mcp/pdf-text-quality.php';
$normal="Rechnung für ausgeführte Arbeiten: 1.234,56 EUR brutto.\n\tSeite 1\fSeite 2 – Prüfung der Beträge.";
if(!mcpPdfTextUsable($normal))throw new RuntimeException('Valid German text rejected');
// The former 70 percent printable threshold accepted this corrupt font map.
$broken=str_repeat("Position 1234\x07 5\x1B7\x0E EUR ",15);
if(mcpPdfTextUsable($broken))throw new RuntimeException('Broken font map accepted');
if(mcpPdfTextUsable('123,45'))throw new RuntimeException('Insufficient extraction accepted');
echo "PASS: valid German/page separators retained; corrupt font mapping and insufficient text force OCR\n";
