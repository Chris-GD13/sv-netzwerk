from pathlib import Path

path = Path('public/intern/api/gf-ai-generate-core.php')
text = path.read_text(encoding='utf-8')
old = "if(!is_array($data))throw new RuntimeException('KI-Antwort war unvollständig oder kein gültiges JSON. Bitte Auftrag erneut starten.');return$data;}"
new = """if(!is_array($data)){
    // A long report can occasionally end with an incomplete JSON object when the model
    // reaches its output budget. Retry once server-side instead of surfacing a false
    // user-facing interruption that requires a manual restart.
    static $jsonRetryDepth=0;
    if($jsonRetryDepth<1){
        $jsonRetryDepth++;
        try{
            $retrySystem=$system."\\nWICHTIG: Antworte ausschließlich als vollständiges, syntaktisch gültiges JSON-Objekt. Keine Markdown-Codeblöcke. Formuliere kompakt, ohne Inhalte oder geforderte Abschnitte wegzulassen.";
            return gfOpenAI($content,$retrySystem,max($maxOutputTokens??9000,16000));
        } finally {
            $jsonRetryDepth--;
        }
    }
    $reason=trim((string)($j['incomplete_details']['reason']??''));
    throw new RuntimeException($reason!==''?'KI-Antwort konnte auch nach automatischer Wiederholung nicht vollständig erzeugt werden ('.$reason.').':'KI-Antwort konnte auch nach automatischer Wiederholung nicht als vollständiges JSON erzeugt werden.');
}return$data;}"""
if 'static $jsonRetryDepth=0;' in text:
    print('GF JSON retry already active.')
    raise SystemExit(0)
if old not in text:
    raise SystemExit('target gfOpenAI JSON guard not found')
path.write_text(text.replace(old, new, 1), encoding='utf-8')
print('GF JSON retry added.')
