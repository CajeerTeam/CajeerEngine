#!/usr/bin/env python3
"""Portable checkpoint schema, atomicity and SQLite backup/restore verification."""
from pathlib import Path
import tempfile
import sqlite3
root=Path(__file__).resolve().parents[1]
files = sorted(p for p in (root/'migrations/sqlite').glob('*.sql') if p.name[:4] <= '0023')
assert files[-1].name=='0023_migration_execution.sql'
with tempfile.TemporaryDirectory(prefix='ce-execution-') as tmp:
    file=Path(tmp)/'site.sqlite'
    db=sqlite3.connect(file)
    db.execute('PRAGMA foreign_keys=ON')
    for migration in files: db.executescript(migration.read_text())
    assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
    print('PASS all prior SQLite schema files and 0023 applied')
    project='a'*32
    data=(project,'dle-db','b'*64,'c'*64,'20261117-000000-'+'d'*16,3,0,'running',None,'2026-11-17','2026-11-17')
    db.execute('INSERT INTO ce_migration_execution(project_id,source,plan_sha256,source_sha256,backup_id,total,cursor_index,state,identities,started_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',data)
    db.commit()
    try:
        with db:
            db.execute('INSERT INTO ce_migration_execution_items(project_id,ordinal,source_type,source_id,result) VALUES(?,?,?,?,?)',(project,0,'post','1','{}'))
            db.execute('UPDATE ce_migration_execution SET cursor_index=1 WHERE project_id=?',(project,))
            raise RuntimeError('crash before commit')
    except RuntimeError: pass
    assert db.execute('SELECT cursor_index FROM ce_migration_execution').fetchone()==(0,)
    assert db.execute('SELECT COUNT(*) FROM ce_migration_execution_items').fetchone()==(0,)
    print('PASS interrupted transaction leaves zero journal entries and old cursor')
    with db:
        db.execute('INSERT INTO ce_migration_execution_items(project_id,ordinal,source_type,source_id,result) VALUES(?,?,?,?,?)',(project,0,'post','1','{}'))
        db.execute('UPDATE ce_migration_execution SET cursor_index=1 WHERE project_id=?',(project,))
    try:
        with db: db.execute('INSERT INTO ce_migration_execution_items(project_id,ordinal,source_type,source_id,result) VALUES(?,?,?,?,?)',(project,1,'post','1','{}'))
        raise AssertionError('Source identity duplicated')
    except sqlite3.IntegrityError: pass
    assert db.execute('SELECT cursor_index FROM ce_migration_execution').fetchone()==(1,)
    print('PASS committed cursor survives failure and unique source ID blocks duplication')
    snapshot=Path(tmp)/'before-migration.sqlite'
    with sqlite3.connect(snapshot) as backup: db.backup(backup)
    with db:
        db.execute('INSERT INTO ce_migration_execution_items(project_id,ordinal,source_type,source_id,result) VALUES(?,?,?,?,?)',(project,1,'post','2','{}'))
        db.execute('UPDATE ce_migration_execution SET cursor_index=2 WHERE project_id=?',(project,))
    assert db.execute('SELECT cursor_index FROM ce_migration_execution').fetchone()==(2,)
    db.close()
    import shutil
    shutil.copy2(snapshot,file)
    db=sqlite3.connect(file)
    assert db.execute('SELECT cursor_index FROM ce_migration_execution').fetchone()==(1,)
    assert db.execute('SELECT COUNT(*) FROM ce_migration_execution_items').fetchone()==(1,)
    assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
    print('PASS actual SQLite backup restoration removes subsequent batch and preserves checkpoint')
