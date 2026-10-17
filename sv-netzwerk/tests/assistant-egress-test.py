"""Provider inventory and assistant egress boundary; no network calls."""
import pathlib,re
root=pathlib.Path(__file__).resolve().parents[1]
api=root/'public/intern/api';hits=[]
for path in api.rglob('*.php'):
 text=path.read_text(encoding='utf-8')
 if 'api.openai.com' in text and 'curl_exec' in text:
  assert not re.search(r'curl_exec\s*\(\s*\$',text),str(path)
  if path.name=='case-work-assistant-provider.php':
   assert 'curl_exec(svAiGuardPaidHandle($ch,$permit,$payload))' in text
   continue
  guards=re.findall(r"curl_exec\(svAiGuardHandle\(\$\w+,'([^']+)'\)\)",text)
  assert len(guards)==text.count('curl_exec('),(str(path),guards)
  hits.extend((str(path.relative_to(api)),i) for i in guards)
assert len(hits)==13,hits
core=(api/'case-work-assistant-core.php').read_text(encoding='utf-8')
endpoint=(api/'case-work-assistant.php').read_text(encoding='utf-8')
assert "define('SVNET_ASSISTANT_REQUEST',true)" in endpoint
assert not re.search(r'curl_exec|file_get_contents\([\'"]https?://|new AiService|krOpenAi|gfOpenAI',core)
assert "new SvAiBudgetLedger($db,$provider?'assistant':'simulation')" in core
# Legacy/assistant histories and worker/document queues may not cross-consume turns.
for name in ['case-work.php','case-work-worker-cli.php','case-work-documents.php']:
 assert 'assistant:v1' in (api/name).read_text(encoding='utf-8'),name
print(f'PASS {len(hits)} explicit legacy transport call sites in {len(set(p for p,_ in hits))} files; paid assistant has one permit-bound provider; existing paths retain their lane')
