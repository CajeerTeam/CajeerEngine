"""Check the actual SQLite migration, account/identity rollback and activation CAS."""
import sqlite3
from pathlib import Path
root = Path(__file__).resolve().parents[1]
db = sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
files = sorted(p for p in (root/'migrations/sqlite').glob('*.sql') if p.name[:4] <= '0020')
for f in files[:-1]: db.executescript(f.read_text())
db.execute("INSERT INTO ce_users(id,email,name,password_hash) VALUES('old','old@example.com','Old','oldhash')")
db.commit()
db.executescript(files[-1].read_text())
assert files[-1].name == '0020_migration_identities.sql'
assert db.execute("SELECT name FROM ce_users WHERE id='old'").fetchone() == ('Old',)
print(f'PASS {len(files)-1} previous migrations + 0020; existing account preserved')
try:
    with db:
        db.execute("INSERT INTO ce_users(id,email,name,password_hash,status) VALUES('new','new@example.com','New','hash','disabled')")
        db.execute("INSERT INTO ce_migration_identities(project_id,source_id,user_id,role_handle,state,created_at) VALUES('p','1','new','viewer','pending','2026-11-16')")
        raise RuntimeError('forced rollback')
except RuntimeError: pass
assert db.execute("SELECT count(*) FROM ce_users WHERE id='new'").fetchone() == (0,)
assert db.execute('SELECT count(*) FROM ce_migration_identities').fetchone() == (0,)
print('PASS account and identity rollback')
with db:
    db.execute("INSERT INTO ce_users(id,email,name,password_hash,status) VALUES('new','new@example.com','New','hash','disabled')")
    db.execute("INSERT INTO ce_migration_identities(project_id,source_id,user_id,role_handle,state,created_at) VALUES('p','1','new','viewer','pending','2026-11-16')")
    assert db.execute("UPDATE ce_migration_identities SET state='activated' WHERE user_id='new' AND state='pending'").rowcount == 1
    assert db.execute("UPDATE ce_users SET status='active' WHERE id='new' AND status='disabled'").rowcount == 1
    assert db.execute("UPDATE ce_migration_identities SET state='activated' WHERE user_id='new' AND state='pending'").rowcount == 0
assert db.execute('PRAGMA integrity_check').fetchone() == ('ok',)
print('PASS activation CAS and SQLite integrity')
