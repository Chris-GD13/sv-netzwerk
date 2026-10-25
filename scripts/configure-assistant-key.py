"""Rotate only the dedicated assistant credential; never print secret values."""
import json
import os
import pathlib
import sys
import tempfile
import urllib.request
from env_key_update import replace_key

root = pathlib.Path(sys.argv[1]).resolve(strict=True)
env_path = root / '.env'
if not (root / 'intern/api/case-work-assistant.php').is_file():
    raise SystemExit('Portal root verification failed')
source = (root / 'intern/api/case-work-assistant.php').read_text()
if 'OPENAI_ASSISTANT_API_KEY' not in source:
    raise SystemExit('Live assistant does not use the dedicated key')
key = sys.stdin.read().strip()
if not key.startswith('sk-') or any(c.isspace() for c in key):
    raise SystemExit('Invalid dedicated key format')
old = env_path.read_bytes()
for line in old.split(b'\n'):
    if line.startswith(b'OPENAI_API_KEY=') and line.split(b'=', 1)[1].strip().strip(b'\"\'') == key.encode('ascii'):
        raise SystemExit('Dedicated key must differ from legacy key')
request = urllib.request.Request('https://api.openai.com/v1/models', headers={'Authorization': 'Bearer ' + key})
try:
    with urllib.request.urlopen(request, timeout=30) as response:
        catalogue = json.load(response)
        if response.status != 200 or not isinstance(catalogue.get('data'), list):
            raise ValueError('Invalid catalogue')
except Exception:
    raise SystemExit('Dedicated key authentication failed; configuration unchanged')
replacement = replace_key(old, b'OPENAI_ASSISTANT_API_KEY', key.encode('ascii'))
stat = env_path.stat()
fd, temporary = tempfile.mkstemp(prefix='.env-assistant-', dir=root)
try:
    with os.fdopen(fd, 'wb') as output:
        output.write(replacement)
        output.flush()
        os.fsync(output.fileno())
    os.chmod(temporary, stat.st_mode & 0o777)
    os.chown(temporary, stat.st_uid, stat.st_gid)
    os.replace(temporary, env_path)
finally:
    if os.path.exists(temporary):
        os.unlink(temporary)
if env_path.read_bytes() != replacement:
    raise SystemExit('Configuration readback failed')
print('Dedicated assistant key configured and independently read back; live source uses dedicated key; OpenAI model catalogue HTTP 200; legacy key unchanged.')
