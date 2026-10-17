"""Synthetic end-to-end test through the actual case-work.php dispatch."""
import json
import os
import pathlib
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as temp:
    env=dict(os.environ, SVNET_TEST_DB=str(pathlib.Path(temp)/'http.sqlite'))
    env.pop('SVNET_TEST_RULE_RELEASE',None)
    env.pop('SVNET_ASSISTANT_TEST_DSN',None)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}'
    proc=subprocess.Popen(['php','-d','extension=pdo_sqlite','-S',f'127.0.0.1:{port}',str(root/'tests/assistant-local-router.php')],cwd=root,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        for attempt in range(50):
            try:
                with urllib.request.urlopen(base+'/intern/versicherungsfaelle/',timeout=1) as response:
                    assert 'SV-Assistent' in response.read().decode();break
            except urllib.error.URLError:time.sleep(.05)
        else:raise AssertionError('Local fixture did not start')
        def post(body,origin=base):
            headers={'Content-Type':'application/json','X-SV-Assistant':'1','Origin':origin}
            request=urllib.request.Request(base+'/intern/api/case-work.php?assistant=1',data=json.dumps(body).encode(),headers=headers)
            try:
                with urllib.request.urlopen(request,timeout=5) as response:return response.status,json.load(response)
            except urllib.error.HTTPError as error:return error.code,json.load(error)
        open_a={'action':'assistant_open','tab_id':'http-test-tab-000001','folder_id':'fixture-a','claim_number':'TEST-A-001'}
        status,result=post(open_a,origin='https://external.invalid');assert status==403 and result['error']=='ORIGIN_FORBIDDEN'
        status,result=post({**open_a,'folder_id':'fixture-c','claim_number':'TEST-C-003'});assert status==403 and result['error']=='CASE_FORBIDDEN'
        status,result=post(open_a);assert status==200;token=result['data']['token']
        ctx={'token':token,'tab_id':open_a['tab_id']}
        start={**ctx,'action':'assistant_start','request_key':'http-operation-0001','task':'report','instruction':'Synthetic report only','route':'assistant'}
        _,result=post(start);job=result['data']['job_id']
        _,same=post(start);assert same['data']['job_id']==job
        for i in range(2):status,result=post({**ctx,'action':'assistant_step','job_id':job});assert status==200
        assert result['data']['status']=='done' and result['data']['result']['total_cents']==120000
        assert result['budget']['spent_micro']==100000 and result['budget']['reserved_micro']==0
        _,b=post({**open_a,'folder_id':'fixture-b','claim_number':'TEST-B-002'})
        status,result=post({**ctx,'action':'assistant_status','job_id':job});assert status==409 and result['error']=='CONTEXT_INVALID'
        status,result=post({'action':'assistant_close','tab_id':open_a['tab_id'],'token':b['data']['token']});assert status==200
        print('PASS HTTP: real endpoint, origin/ACL checks, idempotence, report, ledger, case switch, close')
    finally:
        proc.terminate();proc.wait(timeout=5)
