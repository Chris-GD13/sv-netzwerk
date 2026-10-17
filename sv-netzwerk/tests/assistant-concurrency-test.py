"""Four actual PHP processes compete for the same 30 EUR ledger."""
import concurrent.futures
import pathlib
import subprocess
import tempfile
root = pathlib.Path(__file__).resolve().parent
with tempfile.TemporaryDirectory() as temp:
    database = str(pathlib.Path(temp) / 'budget.sqlite')
    init = f"require '{(root/'assistant-fixture.php').as_posix()}'; $c=assistantTestCases(); $e=new CaseWorkAssistant(assistantTestDb($argv[1]),assistantTestLoader($c),assistantTestRelease(),'race-session'); $e->schema();"
    subprocess.run(['php', '-d', 'extension=pdo_sqlite', '-r', init, database], check=True)
    def run(i):
        r = subprocess.run(['php', '-d', 'extension=pdo_sqlite', str(root/'assistant-budget-race.php'), database, str(i)], capture_output=True, text=True, check=True)
        return r.stdout
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        results = list(pool.map(run, range(4)))
    assert sum('job_id' in r for r in results) == 1, results
    assert results.count('BUDGET_BLOCKED') == 3, results
    import sqlite3
    with sqlite3.connect(database) as db:
        reserved, spent = db.execute("select sum(reserved_micro),sum(spent_micro) from case_work_budget where month_key!='mutex'").fetchone()
        assert reserved == 20000000 and spent == 0
        assert db.execute('select count(*) from case_work_turns').fetchone()[0] == 1
    db.close()
    print('PASS 4 real processes: 1 reservation, 3 blocked, no orphan jobs, 20/30 EUR reserved')

# The future real-assistant account uses the identical mutex/transaction engine,
# but must remain isolated from simulation even under simultaneous reservations.
with tempfile.TemporaryDirectory() as temp:
    database=str(pathlib.Path(temp)/'real-budget.sqlite')
    fixture=(root/'assistant-fixture.php').as_posix()
    setup="require '"+fixture+"';$l=new SvAiBudgetLedger(assistantTestDb($argv[1]),'assistant');$l->initializeMutex();$l->schema();"
    subprocess.run(['php','-d','extension=pdo_sqlite','-r',setup,database],check=True)
    code="require '"+fixture+"';$l=new SvAiBudgetLedger(assistantTestDb($argv[1]),'assistant');try{$l->transaction(fn()=>$l->reserve('real-'.$argv[2],'2026-10',20000000));echo 'RESERVED';}catch(RuntimeException $e){if($e->getMessage()!=='MONTHLY_BUDGET_EXCEEDED')throw $e;echo 'BLOCKED';}"
    def reserve_real(i):
        return subprocess.run(['php','-d','extension=pdo_sqlite','-r',code,database,str(i)],check=True,capture_output=True,text=True).stdout
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool: results=list(pool.map(reserve_real,range(4)))
    assert results.count('RESERVED')==1 and results.count('BLOCKED')==3,results
    connection=sqlite3.connect(database)
    assert connection.execute("select reserved_micro from case_work_ai_budget where month_key='2026-10'").fetchone()[0]==20000000
    assert connection.execute('select count(*) from case_work_usage').fetchone()[0]==0
    connection.close()
    print('PASS 4 real processes on real-assistant ledger: 1 reservation, 3 blocked, simulation untouched; no provider dispatch')
