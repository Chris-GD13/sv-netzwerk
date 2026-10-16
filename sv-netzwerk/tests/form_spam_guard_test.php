<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/form-spam-guard.php';
function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
expect(sv_form_campaign_blocked(['Name'=>'RobertVom'], ['REMOTE_ADDR'=>'192.0.2.1']), 'Campaign name must be blocked even after IP rotation');
expect(sv_form_campaign_blocked(['Name'=>'Other'], ['REMOTE_ADDR'=>'80.94.95.202']), 'Known campaign address must be blocked');
expect(!sv_form_campaign_blocked(['Name'=>'Robert Vogt'], ['REMOTE_ADDR'=>'192.0.2.1']), 'Legitimate similar name must remain allowed');
expect(!sv_form_campaign_blocked(['Name'=>['Unexpected']], ['REMOTE_ADDR'=>'192.0.2.1']), 'Malformed name must not cause a type error');
$dir=sys_get_temp_dir().'/svnet-form-test-'.bin2hex(random_bytes(6));
try {
    for($i=0;$i<8;$i++) expect(sv_form_rate_allowed('192.0.2.1',$dir,10000+$i), 'First eight valid requests must work');
    expect(!sv_form_rate_allowed('192.0.2.1',$dir,10008), 'Ninth request must be blocked');
    expect(sv_form_rate_allowed('192.0.2.2',$dir,10008), 'Another visitor must be unaffected');
    expect(sv_form_rate_allowed('192.0.2.1',$dir,10910), 'Visitor must recover after the time window');
    echo "Public form spam guard: all checks passed\n";
} finally {
    foreach(glob($dir.'/*.json') ?: [] as $file) unlink($file);
    if(is_dir($dir)) rmdir($dir);
}
