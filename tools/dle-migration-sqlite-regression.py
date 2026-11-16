#!/usr/bin/env python3
"""Real SQLite upgrade and transaction check (does not replace PHP importer E2E)."""
import json
import sqlite3
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='ce-dle-sqlite-') as folder:
    db = sqlite3.connect(Path(folder) / 'site.sqlite')
    db.execute('PRAGMA foreign_keys=ON')
    files = sorted(p for p in (ROOT / 'migrations/sqlite').glob('*.sql') if p.name[:4] <= '0018')
    assert files[-1].name == '0018_dle_migration.sql'
    for migration in files[:-1]:
        db.executescript(migration.read_text())
    db.execute("INSERT INTO ce_content_types(id,handle,name) VALUES('type','articles','Articles')")
    db.execute("INSERT INTO ce_content_entries(id,content_type_id,slug,title) VALUES('old','type','old','Old')")
    db.commit()
    db.executescript(files[-1].read_text())
    db.executescript(files[-1].read_text())
    assert db.execute("SELECT title FROM ce_content_entries WHERE id='old'").fetchone() == ('Old',)
    print(f'PASS {len(files)-1} previous migrations + 0018, old content preserved')
    receipt = ('a'*32, 'b'*64, '2026-11-16T10:00:00Z', json.dumps({'records': [{'source_id': '1', 'target_id': 'new'}]}))
    try:
        with db:
            db.execute("INSERT INTO ce_content_entries(id,content_type_id,slug,title) VALUES('new','type','new','New')")
            db.execute('INSERT INTO ce_dle_migration_runs VALUES(?,?,?,?)', receipt)
            raise RuntimeError('forced failure')
    except RuntimeError:
        pass
    assert db.execute("SELECT COUNT(*) FROM ce_content_entries WHERE id='new'").fetchone() == (0,)
    assert db.execute('SELECT COUNT(*) FROM ce_dle_migration_runs').fetchone() == (0,)
    print('PASS SQL transaction rolls back content and receipt together')
    with db:
        db.execute("INSERT INTO ce_content_entries(id,content_type_id,slug,title) VALUES('new','type','new','New')")
        db.execute('INSERT INTO ce_dle_migration_runs VALUES(?,?,?,?)', receipt)
    try:
        with db:
            db.execute('INSERT INTO ce_dle_migration_runs VALUES(?,?,?,?)', receipt)
    except sqlite3.IntegrityError:
        pass
    else:
        raise AssertionError('duplicate receipt accepted')
    assert db.execute('PRAGMA integrity_check').fetchone() == ('ok',)
    assert db.execute('SELECT result FROM ce_dle_migration_runs').fetchone() == (receipt[-1],)
    print('PASS unique receipt, committed content and database integrity')
    db.close()
