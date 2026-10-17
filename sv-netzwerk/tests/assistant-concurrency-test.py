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
