from pathlib import Path
import concurrent.futures,json,subprocess,time,os
import argparse
parser=argparse.ArgumentParser();parser.add_argument('--report',required=True);args=parser.parse_args()
repo=Path(__file__).resolve().parents[2];project=repo/'sv-netzwerk';report_path=Path(args.report)
js=list((project/'tests').glob('claims_native_*-test.mjs'))+[project/'tests'/name for name in ['claims_native_navigation_test.mjs','claims_notes_test.mjs','claims_source_transport_test.mjs','claimsforce_worker_source_refresh_test.mjs','claimsforce_worker_handshake_test.mjs','claimsforce_source_restrictions_test.mjs','claimsforce_single_scope_test.mjs','claimsforce_import_test.mjs','rekon_bridge_readiness_test.mjs','rekon_import_test.mjs','claimsforce_resume_controls_test.mjs','assistant-ui-test.mjs','assistant-integration-ui-test.mjs','case_work_ui_test.mjs']]
php=[project/'tests'/name for name in ['notes_import_test.php','claimsforce_source_storage_test.php','claimsforce_context_snapshot_test.php','storage_integrity_test.php','claimsforce_single_result_test.php','profile_routing_test.php','case_merge_storage_test.php','document_review_workflow_test.php','case_work_test.php','case_analysis_test.php','case_analysis_worker_test.php','assistant-core-test.php','assistant-integration-budget-test.php','bki_file_cache_test.php','kva_openai_error_test.php','kva_signature_configuration_test.php']]
commands=[(p.name,['node',str(p)]) for p in js]+[(p.name,['php','-d','extension=pdo_sqlite','-d','extension=fileinfo',str(p)]) for p in php]+[(name,['python',str(project/'tests'/name)]) for name in ['assistant-concurrency-test.py','assistant-http-test.py','assistant-egress-test.py']]
logs=report_path.parent/(report_path.stem+'-logs');logs.mkdir(parents=True,exist_ok=True)
def run(item):
 name,cmd=item;start=time.monotonic()
 try:
  r=subprocess.run(cmd,cwd=repo,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=300,env={**os.environ,'SVNET_AI_TRANSPORT_MODE':'deny'})
  (logs/(name+'.log')).write_text(r.stdout+'\n'+r.stderr,encoding='utf-8')
  return {'test':name,'ok':r.returncode==0,'seconds':round(time.monotonic()-start,2),'failure':(r.stdout+r.stderr)[-2200:] if r.returncode else None}
 except Exception as e:return {'test':name,'ok':False,'failure':str(e)}
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:results=list(pool.map(run,commands))
report={'groups':len(results),'passed':sum(r['ok'] for r in results),'tests':results,'scope':'Synthetic isolated tests only; no paid model or production writes.'}
report_path.write_text(json.dumps(report,indent=2),encoding='utf-8')
print(json.dumps(report,indent=2));raise SystemExit(0 if all(r['ok'] for r in results) else 1)
