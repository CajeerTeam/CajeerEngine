#!/usr/bin/env python3
"""Real SQLite schema upgrade and backup/restore; independent of PHP PDO availability."""
import sqlite3, tempfile, json, hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='ce-analytics-ga-') as d:
    db=sqlite3.connect(Path(d)/'site.sqlite')
    migrations=sorted((ROOT/'migrations/sqlite').glob('*.sql'))
    for migration in migrations[:-1]: db.executescript(migration.read_text())
    assert migrations[-1].name=='0017_analytics_sync_runs.sql'
    source='a'*32; encrypted='opaque-encrypted-fixture'; report=json.dumps({'provider':'ga4','resource':'123','metrics':['activeUsers'],'sections':{'overview':{'rows':[{'label':'Total','values':[20]}]}}})
    db.execute("INSERT INTO analytics_connections (id,provider,name,resource_id,enabled,revision,credential,lease_until) VALUES (?,'ga4','Site','123',1,1,?,0)",(source,encrypted))
    db.execute('INSERT INTO analytics_reports VALUES (?,?,?,?,?)',(source,'2026-01-11','2026-01-15','2026-01-20T00:00:00Z',report));db.commit()
    db.executescript(migrations[-1].read_text());db.executescript(migrations[-1].read_text())
    assert db.execute('SELECT credential FROM analytics_connections').fetchone()[0]==encrypted
    assert db.execute('SELECT report_json FROM analytics_reports').fetchone()[0]==report
    assert db.execute('PRAGMA integrity_check').fetchone()==('ok',)
    print(f'PASS {len(migrations)-1} prior SQLite migrations + 0017; existing report and credential unchanged')
    k=hashlib.sha256(b'job').hexdigest();params=(source,1,k,'2026-01-11','2026-01-15','running',1,'2026-01-20T00:00:00Z')
    db.execute('INSERT INTO analytics_sync_runs(connection_id,revision,job_key,date_from,date_to,status,attempts,started_at) VALUES (?,?,?,?,?,?,?,?)',params)
    db.commit()
    try: db.execute('INSERT INTO analytics_sync_runs(connection_id,revision,job_key,date_from,date_to,status,attempts,started_at) VALUES (?,?,?,?,?,?,?,?)',params);raise AssertionError('duplicate receipt accepted')
    except sqlite3.IntegrityError:db.rollback()
    with db:
        db.execute("UPDATE analytics_sync_runs SET status='success',finished_at='2026-01-20T00:00:01Z' WHERE connection_id=? AND revision=1 AND job_key=?",(source,k))
        db.execute("UPDATE analytics_connections SET last_success='2026-01-20T00:00:01Z' WHERE id=?",(source,))
    print('PASS unique receipt and transactional status update')
    backup=sqlite3.connect(Path(d)/'backup.sqlite');db.backup(backup);backup.close()
    db.execute('DELETE FROM analytics_sync_runs');db.execute('DELETE FROM analytics_reports');db.commit();db.close()
    backup=sqlite3.connect(Path(d)/'backup.sqlite');restored=sqlite3.connect(Path(d)/'restored.sqlite');backup.backup(restored)
    assert restored.execute('PRAGMA integrity_check').fetchone()==('ok',)
    assert restored.execute('SELECT credential FROM analytics_connections').fetchone()[0]==encrypted
    assert restored.execute('SELECT report_json FROM analytics_reports').fetchone()[0]==report
    assert restored.execute('SELECT status FROM analytics_sync_runs').fetchone()==('success',)
    print('PASS actual SQLite backup and restore retains report, credential and receipt')
    restored.close();backup.close()
