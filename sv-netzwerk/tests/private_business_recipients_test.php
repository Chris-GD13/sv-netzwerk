<?php
declare(strict_types=1);
require __DIR__.'/../public/intern/api/private-business-core.php';
require __DIR__.'/../public/intern/api/private-business-mail.php';
$r=pbRecipients('first@example.com; second@example.com; FIRST@example.com');
if($r!==['FIRST@example.com','second@example.com'])throw new RuntimeException('Recipients must split and deduplicate');
foreach(['','good@example.com; invalid','good@example.com; injected@example.com'."\r\nBcc: other@example.com"]as$input){try{pbRecipients($input);throw new LogicException('Invalid recipient accepted');}catch(RuntimeException$e){}}
$d=pbClean(['email'=>'first@example.com','additional_email'=>'second@example.com','mobile'=>'+49 170 1234567','other_phone'=>'0711 123456'],'christian');
if($d['additional_email']!=='second@example.com'||$d['mobile']!=='+49 170 1234567'||$d['other_phone']!=='0711 123456')throw new RuntimeException('Contact fields lost');
if(pbMailSender(['email'=>'cw@sv-schuett.eu'])!=='ws@sv-schuett.eu')throw new RuntimeException('Forms must use Susanne sender');
if(pbMailSender(['email'=>'ks@sv-schuett.eu'])!=='ks@sv-schuett.eu'||pbMailSender(['email'=>'cw@sv-schuett.eu'],['data'=>['correspondence_email'=>'ks@sv-schuett.eu']])!=='ks@sv-schuett.eu')throw new RuntimeException('Katja sender must follow creator and saved record');
echo "private_business_recipients_test: ok (multiple recipients, deduplication, invalid addresses, contact fields, Susanne sender)\n";
