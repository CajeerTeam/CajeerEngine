#!/usr/bin/env python3
"""Reproduce 0024 upgrade, atomic sync checkpoint and rollback on a real SQLite database."""
from pathlib import Path
import sqlite3
import tempfile
root=Path(__file__).resolve().parents[1]
migrations=sorted((root/'migrations/sqlite').glob('*.sql'))
assert migrations[-1].name=='0024_migration_validation_cutover.sql'
with tempfile.TemporaryDirectory(prefix='ce-cutover-') as temp:
    path=Path(temp)/'site.sqlite';db=sqlite3.connect(path);db.execute('PRAGMA foreign_keys=ON')
    for f in migrations:db.executescript(f.read_text())
    assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
    print('PASS full SQLite upgrade through 0024')
    project='a'*32
    with db:
        db.execute('INSERT INTO ce_migration_validation VALUES(?,?,?,?,?,?)',(project,'b'*64,'c'*64,0,'{"changes":1}','2026-11-17'))
        db.execute('INSERT INTO ce_migration_sync_runs VALUES(?,?,?,?,?,?,?,?)',(project,'b'*64,'c'*64,'20261117-000000-'+'d'*16,0,2,'running','2026-11-17'))
    try:
        with db:
            db.execute('INSERT INTO ce_migration_sync_items VALUES(?,?,?,?,?,?,?)',(project,'post','1','e'*64,'id-1',1,'2026-11-17'))
            db.execute('UPDATE ce_migration_sync_runs SET cursor_index=1 WHERE project_id=?',(project,))
            raise RuntimeError('simulated crash')
    except RuntimeError:pass
    assert db.execute('SELECT cursor_index FROM ce_migration_sync_runs').fetchone()==(0,)
    assert db.execute('SELECT COUNT(*) FROM ce_migration_sync_items').fetchone()==(0,)
    print('PASS crash rolls back both delta item and checkpoint')
    with db:
        db.execute('INSERT INTO ce_migration_sync_items VALUES(?,?,?,?,?,?,?)',(project,'post','1','e'*64,'id-1',1,'2026-11-17'))
        db.execute('UPDATE ce_migration_sync_runs SET cursor_index=1 WHERE project_id=?',(project,))
    try:
        with db:db.execute('INSERT INTO ce_migration_sync_items VALUES(?,?,?,?,?,?,?)',(project,'post','1','e'*64,'id-1',1,'2026-11-17'))
        raise AssertionError('Duplicate source identity permitted')
    except sqlite3.IntegrityError:pass
    assert db.execute('SELECT cursor_index FROM ce_migration_sync_runs').fetchone()==(1,)
    print('PASS committed sync checkpoint and unique source key')
    snapshot=Path(temp)/'backup.sqlite'
    with sqlite3.connect(snapshot) as backup:db.backup(backup)
    with db:
        db.execute('INSERT INTO ce_migration_cutovers VALUES(?,?,?,?,?)',(project,'b'*64,'https://example.com','20261117-000000-'+'d'*16,'2026-11-17'))
    db.close()
    import shutil
    shutil.copy2(snapshot,path)
    with sqlite3.connect(path) as restored:
        assert restored.execute('SELECT COUNT(*) FROM ce_migration_cutovers').fetchone()[0]==0
        assert restored.execute('SELECT cursor_index FROM ce_migration_sync_runs').fetchone()[0]==1
        assert restored.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
    print('PASS full SQLite backup restoration rolls back activation')
