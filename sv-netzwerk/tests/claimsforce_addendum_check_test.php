<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/claimsforce-addendum-check.php';
require_once __DIR__.'/../public/intern/api/claimsforce-addendum-rules.php';

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$binding = ['caseId'=>'11111111-1111-4111-8111-111111111111','profile'=>'christian','claimNumber'=>'SYNTHETISCH-01'];
$reportId = '22222222-2222-4222-8222-222222222222';
$operationId = '33333333-3333-4333-8333-333333333333';
$formId = '44444444-4444-4444-8444-444444444444';
$attachment = ['id'=>'55555555-5555-4555-8555-555555555555','name'=>'Rechnung.pdf','version'=>'2026-11-19'];
[$version,$sender] = cfaSender("Stand: Test · konsolidierte Fassung 4.42\nUnter jeden fertigen Bericht, Nachtrag, Brief und E-Mail-Text:\n\nTest Name  \nSachverständiger  \nTest Unternehmen  \nZertifizierung  \ntest@example.invalid  \nhttps://example.invalid/\n");
expect($version === '4.42' && !str_contains($sender, "  \n"),'Master and sender extraction');
expect(cfaRoute('SV SparkassenVersicherung','SYNTHETISCH-01 GF') === 'sv_gf','GF routing');
expect(cfaRoute('Test Versicherung','SYNTHETISCH-01 GF') === 'claimsforce','GF without insurer match');
expect(cfaOrder('', ['Nachtrag']) && !cfaOrder('Normaler SV-Schaden',['SE']),'Workflow routing');
expect(cfaCents('1.024,30 EUR')===102430 && cfaCents('1,024')===null,'Cent conversion');

$payments = [
    ['date'=>'2026-11-01','recipient'=>'Test VN','category'=>'vn','status'=>'paid','amount'=>'100,02','evidence'=>'Zahlbeleg A'],
    ['date'=>'2026-11-02','recipient'=>'Mey Generalbau','category'=>'partner','status'=>'paid','amount'=>'30,09','evidence'=>'Zahlbeleg B'],
    ['date'=>'2026-11-03','recipient'=>'Deutsche Assist','category'=>'internal','status'=>'paid','amount'=>'24,00','evidence'=>'Kostenbeleg C'],
    ['date'=>'2026-11-20','recipient'=>'Test VN','category'=>'vn','status'=>'open','amount'=>'5,49','evidence'=>'Bestätigung D']
];
$ledger = cfaLedger($payments, 13560);
expect($ledger['issues']===[] && $ledger['paid']===13011 && $ledger['open']===549 && count($ledger['rows'])===3,'Internal costs or cent rounding affected payments');
$invalid = $payments;
$invalid[1]['category'] = 'vn';
expect(str_contains(implode(' ', cfaLedger($invalid,13560)['issues']),'Partnerfirmen'),'Mey in VN source row blocked');
$invalid = $payments;
$invalid[0]['evidence'] = '';
expect(!empty(cfaLedger($invalid,13560)['issues']),'Unclear payment blocks completion');

$plan = compact('binding','reportId','operationId','ledger') + ['body'=>'Die Rechnung ist geprüft.',
    'salutation'=>'Sehr geehrte Damen und Herren,','rules'=>['sender'=>$sender], 'attachments'=>[$attachment],
    'forms'=>['ZB'=>$formId],'paymentFields'=>['ZB'=>['paid'=>'Geleistete Zahlungen','open'=>'Offene Schlusszahlung']]];
$report = ['id'=>$reportId,'status'=>'draft','text'=>cfaTemplate($plan),'attachments'=>[$attachment]];
$form = $binding + ['formId'=>$formId,'status'=>'draft','dirty'=>false,'fields'=>[
    ['label'=>'Geleistete Zahlungen','value'=>'130,11'],
    ['label'=>'Offene Schlusszahlung','value'=>'5,49'],
    ['label'=>'Empfänger Versicherungsnehmer','value'=>'Test VN'],
    ['label'=>'Empfänger Partnerfirma','value'=>'Mey Generalbau']
]];
$result = $binding + compact('operationId','report') + ['source'=>'claimsforce-native-addendum','readOnly'=>true,'forms'=>['ZB'=>$form]];
expect(cfaCheck($plan,$result)===[],'Clean report failed');
$rich = $result;
$rich['report']['text']='<p>'.str_replace("\n",'<br>',htmlspecialchars($report['text'])).'</p>';
expect(cfaCheck($plan,$rich)===[],'Rich text conversion failed');

$cases = [
    'Anrede' => static function(array &$data) { $data['report']['text']=preg_replace('/^.*\n\n/','',$data['report']['text'],1); },
    'Absender' => static function(array &$data) { $data['report']['text']=str_replace('Test Unternehmen','Alter Absender',$data['report']['text']); },
    'unerwartete Anlage' => static function(array &$data) { $data['report']['attachments'][]=['id'=>'older','name'=>'Rechnung.pdf']; },
    'falsche oder nicht belegte Fassung' => static function(array &$data) { $data['report']['attachments'][0]['version']='2026-10-01'; },
    'interne SV-Kosten' => static function(array &$data) { $data['forms']['ZB']['fields'][]=['label'=>'Abzug','value'=>'Deutsche Assist 24,00']; },
    'Partnerblock' => static function(array &$data) { $data['forms']['ZB']['fields'][2]['value']='Mey Generalbau'; },
    'Offene Schlusszahlung' => static function(array &$data) { $data['forms']['ZB']['fields'][1]['value']='5,48'; },
    'Konto' => static function(array &$data) { $data['profile']='jens'; },
    'Schadennummer' => static function(array &$data) { $data['claimNumber']='FALSCH'; },
    'Browsernachweis' => static function(array &$data) { $data=['status'=>'done']; }
];
foreach ($cases as $expected=>$mutate) {
    $bad = $result;
    $mutate($bad);
    expect(str_contains(implode(' ',cfaCheck($plan,$bad)),$expected),"$expected did not block completion");
}
$small = array_replace($plan,['ledger'=>null,'forms'=>[],'attachments'=>[]]);
$smallResult = array_replace($result,['report'=>array_replace($report,['text'=>cfaTemplate($small),'attachments'=>[]]),'forms'=>[]]);
$start = hrtime(true);
for ($n=0;$n<1000;$n++) expect(cfaCheck($small,$smallResult)===[],'Small order failed');
printf("Serverprüfung kleiner Nachtrag: %.3f ms im Mittel (1000 Durchläufe, ohne I/O).\n",(hrtime(true)-$start)/1e9);
