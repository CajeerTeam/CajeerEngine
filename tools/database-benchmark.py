#!/usr/bin/env python3
"""Reproducible SQLite SQL benchmark on a disposable 100k-entry database (stdlib only).
Measures repository page SQL, not HTTP or PHP latency. Never opens a user DB.
"""
import argparse
import json
import sqlite3
import statistics
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def run(size: int):
    with tempfile.TemporaryDirectory(prefix='ce-perf-') as temp:
        db = sqlite3.connect(Path(temp) / 'bench.sqlite')
        db.execute('PRAGMA foreign_keys=ON')
        migrations = sorted((ROOT / 'migrations/sqlite').glob('*.sql'))
        for path in migrations[:-1]:
            db.executescript(path.read_text())
        assert migrations[-1].name == '0013_database_performance.sql'
        db.executemany('INSERT INTO ce_content_types(id,handle,name) VALUES (?,?,?)', [(f'type{i}', f'type{i}', f'Type {i}') for i in range(5)])
        data = json.dumps({'body': 'Content ' * 32})
        db.executemany('INSERT INTO ce_content_entries(id,content_type_id,slug,locale,status,title,data,translation_group,updated_at,deleted_at) VALUES (?,?,?,?,?,?,?,?,?,?)', (
            (f'{i:09}', f'type{i%5}', f'page-{i}', 'ru' if i%3 else 'en', 'published' if i%7 else 'draft', f'Title {i}', data, f'{i:09}', f'2026-01-{1+i%28:02} 12:{i%60:02}:00', '2026-02-01' if i%31==0 else None)
            for i in range(size)))
        db.commit()
        # Matches ContentEntryRepository::page, including deterministic secondary ordering.
        query = 'SELECT e.* FROM ce_content_entries e WHERE e.content_type_id=? AND e.deleted_at IS NULL ORDER BY e.updated_at DESC,e.id DESC LIMIT ? OFFSET ?'
        args = ('type0', 50, 1000)
        def sample():
            db.execute('ANALYZE')
            plan = [r[3] for r in db.execute('EXPLAIN QUERY PLAN '+query,args)]
            rows = db.execute(query,args).fetchall()
            for _ in range(3): db.execute(query,args).fetchall()
            durations=[]
            for _ in range(30):
                start=time.perf_counter_ns();db.execute(query,args).fetchall();durations.append((time.perf_counter_ns()-start)/1e6)
            return plan, rows, round(statistics.median(durations),3)
        old, old_rows, before = sample()
        db.executescript(migrations[-1].read_text())
        new, new_rows, after = sample()
        assert old_rows==new_rows and len(new_rows)==50
        assert any('ce_entries_type_live_updated_idx' in row for row in new),new
        assert not any('TEMP B-TREE' in row for row in new),new
        for clause,index,param in [('locale=?','ce_entries_type_locale_live_updated_idx','ru'),('status=?','ce_entries_type_status_live_updated_idx','published')]:
            plan=[r[3] for r in db.execute('EXPLAIN QUERY PLAN SELECT e.* FROM ce_content_entries e WHERE e.content_type_id=? AND e.deleted_at IS NULL AND '+clause+' ORDER BY e.updated_at DESC,e.id DESC LIMIT 50',('type0',param))]
            assert any(index in row for row in plan) and not any('TEMP B-TREE' in row for row in plan),plan
        assert db.execute('PRAGMA integrity_check').fetchone()==('ok',)
        assert db.execute('PRAGMA foreign_key_check').fetchall()==[]
        # Verify the database-side compare-and-swap expression used by the repository.
        target=new_rows[0][0]
        assert db.execute('UPDATE ce_content_entries SET revision_number=revision_number+1 WHERE id=? AND revision_number=1 AND deleted_at IS NULL',(target,)).rowcount==1
        assert db.execute('UPDATE ce_content_entries SET revision_number=revision_number+1 WHERE id=? AND revision_number=1 AND deleted_at IS NULL',(target,)).rowcount==0
        db.rollback()
        assert db.execute('SELECT revision_number FROM ce_content_entries WHERE id=?',(target,)).fetchone()==(1,)
        print(json.dumps({'rows':size,'sqlite_version':sqlite3.sqlite_version,'page_size':50,'offset':1000,'samples':30,'before_median_ms':before,'after_median_ms':after,'before_plan':old,'after_plan':new,'rows_identical':True,'integrity_check':'ok','foreign_key_check':'ok','compare_and_swap':'pass'},indent=2))
        db.close()

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--rows',type=int,default=100000);args=parser.parse_args()
    if not 10000<=args.rows<=1000000: parser.error('rows must be 10,000..1,000,000')
    run(args.rows)
