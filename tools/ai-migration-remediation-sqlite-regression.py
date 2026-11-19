#!/usr/bin/env python3
"""Real SQLite constraints and CAS rollback for approval journal (independent of PHP PDO)."""
import sqlite3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
db=sqlite3.connect(':memory:');db.execute('PRAGMA foreign_keys=ON')
for file in sorted((root/'migrations/sqlite').glob('*.sql')): db.executescript(file.read_text())
assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
project='a'*32;operator='b'*36;proposal='c'*36;now=1730000000
with db:
    db.execute('INSERT INTO ce_ai_migration_remediations (id,project_id,user_id,kind,snapshot_json,state,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?)',
        (proposal,project,operator,'restore_redirect','{"source_path":"/legacy","target_path":"/target"}','pending',now,now+3600))
assert db.execute('UPDATE ce_ai_migration_remediations SET state=? WHERE id=? AND user_id=? AND state=?',('running',proposal,'other','pending')).rowcount==0
try:
    with db:
        assert db.execute('UPDATE ce_ai_migration_remediations SET state=? WHERE id=? AND user_id=? AND state=?',('running',proposal,operator,'pending')).rowcount==1
        db.execute("INSERT INTO ce_cms_redirects (id,source_path,target_url,status_code,enabled,hit_count,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)",
                   ('d'*36,'/legacy','/target',301,1,0,'2026-11-19','2026-11-19'))
        raise RuntimeError('simulated IO or route index failure')
except RuntimeError:pass
assert db.execute('SELECT state FROM ce_ai_migration_remediations WHERE id=?',(proposal,)).fetchone()==('pending',)
assert db.execute('SELECT count(*) FROM ce_cms_redirects').fetchone()==(0,)
with db:
    assert db.execute('UPDATE ce_ai_migration_remediations SET state=? WHERE id=? AND state=?',('running',proposal,'pending')).rowcount==1
    db.execute("INSERT INTO ce_cms_redirects (id,source_path,target_url,status_code,enabled,hit_count,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)",
               ('d'*36,'/legacy','/target',301,1,0,'2026-11-19','2026-11-19'))
    assert db.execute('UPDATE ce_ai_migration_remediations SET state=?,result_json=? WHERE id=? AND state=?',('applied','{"verified":true}',proposal,'running')).rowcount==1
assert db.execute('UPDATE ce_ai_migration_remediations SET state=? WHERE id=? AND state=?',('running',proposal,'pending')).rowcount==0
assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
print('PASS SQLite 0039, operator ownership, rollback, single-use CAS, real redirect persistence')
