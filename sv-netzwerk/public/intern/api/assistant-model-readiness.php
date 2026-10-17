<?php
declare(strict_types=1);
/** Fixed read-only catalogue probe. Cannot submit inference or upload case data. */
function svAssistantModelCatalogue(string $key): array {
    $ch=curl_init('https://api.openai.com/v1/models');
    curl_setopt_array($ch,[CURLOPT_HTTPGET=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key]]);
    $raw=curl_exec(svAssistantCatalogueGuard($ch));$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $body=is_string($raw)?json_decode($raw,true):null;$available=[];
    foreach($body['data']??[] as $model)if(in_array($model['id']??'', ['gpt-6-luna','gpt-5-mini','gpt-4.1-mini'],true))$available[]=$model['id'];
    return ['http_status'=>$status,'available'=>$available];
}
function svAssistantCatalogueGuard($ch): mixed {
    if(getenv('SVNET_AI_TRANSPORT_MODE')==='deny')throw new RuntimeException('PAID_AI_DISABLED');
    if(curl_getinfo($ch,CURLINFO_EFFECTIVE_URL)!=='https://api.openai.com/v1/models')throw new RuntimeException('AI_CATALOGUE_TARGET_INVALID');
    return $ch;
}
