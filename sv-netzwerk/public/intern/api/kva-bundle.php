<?php
declare(strict_types=1);

/** Read scanned bundles by content; filenames never establish an offer. */
function kvaBundleCommand(array $command): string
{
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)) throw new RuntimeException('PDF-Seitenprüfung konnte nicht gestartet werden.');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]); fclose($pipes[2]);
    if(proc_close($process)!==0) throw new RuntimeException('PDF-Seitenprüfung fehlgeschlagen: '.substr(trim($error),0,200));
    return trim($out);
}
function kvaBundleTemp(): string
{
    $base=tempnam(sys_get_temp_dir(),'kva-bundle-');
    if($base===false) throw new RuntimeException('PDF konnte nicht vorbereitet werden.');
    $path=$base.'.pdf'; if(!rename($base,$path)) { @unlink($base); throw new RuntimeException('PDF konnte nicht vorbereitet werden.'); }
    return $path;
}
function kvaBundleMerge(array $existing,array $found,int $start,int $end): array
{
    foreach($found as $offer){
        if(!is_array($offer)||($offer['has_priced_positions']??false)!==true) continue;
        $number=trim((string)($offer['quote_number']??'')); $company=trim((string)($offer['company']??''));
        if($number===''||$company==='') continue;
        $key=hash('sha256',strtolower(preg_replace('/\s+/u','',$company.'|'.$number)));
        if(isset($existing[$key])) { $existing[$key]['start']=min($start,$existing[$key]['start']); $existing[$key]['end']=max($end,$existing[$key]['end']); }
        else $existing[$key]=['key'=>$key,'company'=>$company,'quote_number'=>$number,'quote_date'=>trim((string)($offer['quote_date']??'')),'start'=>$start,'end'=>$end];
    }
    return $existing;
}
/** Callback reads a short original PDF block; selection is bound to its SHA-256. */
function kvaBundlePrepare(string $name,string $mime,string $bytes,string $selected,callable $read,callable $get,callable $set,string $scope): array
{
    if($mime!=='application/pdf'&&!str_starts_with($bytes,'%PDF-')) return ['bytes'=>$bytes,'quote_number'=>''];
    $source=kvaBundleTemp(); file_put_contents($source,$bytes);
    try {
        $pages=(int)kvaBundleCommand(['/usr/bin/qpdf','--show-npages',$source]);
        if($pages<1) throw new RuntimeException('PDF enthält keine lesbaren Seiten.');
        if($pages<=12&&$selected==='') return ['bytes'=>$bytes,'quote_number'=>''];
        $cacheKey='kva_bundle_v1_'.hash('sha256',$scope.'|'.hash('sha256',$bytes));
        $cached=json_decode($get($cacheKey),true); $offers=is_array($cached['offers']??null)?$cached['offers']:null;
        if($offers===null){
            $offers=[];
            for($start=1;$start<=$pages;$start+=10){
                $end=min($pages,$start+11); $part=kvaBundleTemp();
                try { kvaBundleCommand(['/usr/bin/qpdf',$source,'--pages','.',$start.'-'.$end,'--',$part]); $found=$read($name,(string)file_get_contents($part)); $offers=kvaBundleMerge($offers,is_array($found['offers']??null)?$found['offers']:[],$start,$end); }
                finally { @unlink($part); }
                if($end===$pages) break;
            }
            $set($cacheKey,json_encode(['offers'=>$offers],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        }
        if(!$offers) throw new RuntimeException('In der Sammeldatei wurde kein Originalangebot mit bepreisten Positionen erkannt.');
        if($selected==='') return ['offers'=>array_values($offers),'selection_required'=>true];
        if(!isset($offers[$selected])) throw new RuntimeException('Die Belegauswahl gehört nicht zu dieser Originaldatei. Bitte erneut auslesen.');
        $offer=$offers[$selected]; $part=kvaBundleTemp();
        try { kvaBundleCommand(['/usr/bin/qpdf',$source,'--pages','.',$offer['start'].'-'.$offer['end'],'--',$part]); return ['bytes'=>(string)file_get_contents($part),'quote_number'=>$offer['quote_number'],'company'=>$offer['company']]; }
        finally { @unlink($part); }
    } finally { @unlink($source); }
}
