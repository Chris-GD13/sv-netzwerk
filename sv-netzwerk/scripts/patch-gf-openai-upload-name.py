from pathlib import Path

root = Path(__file__).resolve().parents[1]
core = root / 'public/intern/api/gf-ai-generate-core.php'
source = core.read_text(encoding='utf-8')
required = [
    "const GF_OPENAI_UPLOAD_POLICY_VERSION='2';",
    'function gfOpenAIUploadExtension',
    "($cached['policy']??'')===GF_OPENAI_UPLOAD_POLICY_VERSION",
]
missing = [needle for needle in required if needle not in source]
if missing:
    raise SystemExit('GF OpenAI upload policy is incomplete: ' + ', '.join(missing))
print('GF OpenAI upload policy is present and versioned.')
