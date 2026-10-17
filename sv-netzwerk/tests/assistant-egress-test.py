"""Fail CI when a known provider transport bypasses the closed gateway."""
import pathlib
import re
root=pathlib.Path(__file__).resolve().parents[1]/'public/intern/api'
count=0
for path in root.rglob('*.php'):
    text=path.read_text(encoding='utf-8')
    if 'api.openai.com' in text and 'curl_exec' in text:
        assert not re.search(r'curl_exec\s*\(\s*\$',text), str(path)
        assert 'svAiGuardHandle' in text, str(path)
        count+=1
assert count>=9
print(f'PASS {count} known provider transport files route through disabled paid egress')
