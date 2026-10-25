<?php
declare(strict_types=1);

/** Local, bounded OCR. Originals never leave the host or change. No model API. */
function mcpPdfOcr(string $bytes): array
{
    $result = ['method'=>'tesseract-deu-eng', 'sha256'=>hash('sha256', $bytes), 'complete'=>false, 'pages'=>[], 'error'=>null];
    if ($bytes === '' || strlen($bytes) > 15728640 || !function_exists('exec')) {
        $result['error']='OCR_INPUT_OR_EXEC_UNAVAILABLE'; return $result;
    }
    foreach (['/usr/bin/pdftoppm','/usr/bin/pdfinfo','/usr/bin/tesseract','/usr/bin/timeout'] as $binary) {
        if (!is_executable($binary)) { $result['error']='OCR_DEPENDENCY_UNAVAILABLE'; return $result; }
    }
    $dir=sys_get_temp_dir().'/svnet-ocr-'.bin2hex(random_bytes(12));
    if (!mkdir($dir,0700)) { $result['error']='OCR_TEMP_FAILED'; return $result; }
    try {
        $pdf=$dir.'/input.pdf';
        if (file_put_contents($pdf,$bytes)!==strlen($bytes)) throw new RuntimeException('OCR_INPUT_WRITE_FAILED');
        $info=[]; $code=1;
        exec('/usr/bin/timeout 10 /usr/bin/pdfinfo '.escapeshellarg($pdf).' 2>/dev/null',$info,$code);
        if ($code!==0 || !preg_match('/^Pages:\s+(\d+)/m',implode("\n",$info),$m)) throw new RuntimeException('OCR_PAGE_COUNT_FAILED');
        $result['page_count']=(int)$m[1];
        // Never silently label the first pages as a complete document.
        if ($result['page_count']<1 || $result['page_count']>30) throw new RuntimeException('OCR_PAGE_LIMIT_30');
        $deadline=microtime(true)+110;
        for ($page=1;$page<=$result['page_count'];$page++) {
            if (microtime(true)>$deadline) throw new RuntimeException('OCR_TIME_LIMIT');
            $prefix=$dir.'/page'; $out=[];
            exec('/usr/bin/timeout 15 /usr/bin/pdftoppm -f '.$page.' -l '.$page.' -singlefile -scale-to 2400 -png '.escapeshellarg($pdf).' '.escapeshellarg($prefix).' 2>/dev/null',$out,$code);
            if ($code!==0 || !is_file($prefix.'.png')) throw new RuntimeException('OCR_RENDER_FAILED_PAGE_'.$page);
            $out=[];
            exec('OMP_THREAD_LIMIT=1 /usr/bin/timeout 20 /usr/bin/tesseract '.escapeshellarg($prefix.'.png').' stdout -l deu+eng --psm 3 2>/dev/null',$out,$code);
            if ($code!==0) throw new RuntimeException('OCR_FAILED_PAGE_'.$page);
            $result['pages'][]=['page'=>$page,'text'=>trim(implode("\n",$out))];
            unlink($prefix.'.png');
        }
        if (trim(implode('',array_column($result['pages'],'text')))==='') throw new RuntimeException('OCR_NO_TEXT_RECOGNIZED');
        $result['complete']=count($result['pages'])===$result['page_count'];
    } catch (Throwable $e) { $result['error']=$e->getMessage(); }
    finally { foreach (glob($dir.'/*') ?: [] as $path) unlink($path); rmdir($dir); }
    return $result;
}
