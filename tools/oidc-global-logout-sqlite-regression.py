#!/usr/bin/env python3
"""Apply the actual migrations to SQLite and check upgrade/storage constraints independently of PHP PDO."""
from pathlib import Path
import sqlite3
root=Path(__file__).resolve().parents[1]
c=sqlite3.connect(':memory:')
c.execute('PRAGMA foreign_keys=ON')
c.executescript('''CREATE TABLE ce_users(id TEXT PRIMARY KEY);
CREATE TABLE ce_api_tokens(id TEXT PRIMARY KEY,user_id TEXT,name TEXT,revoked_at TEXT,expires_at TEXT);''')
c.executescript((root/'migrations/sqlite/0028_unified_sessions.sql').read_text())
c.executescript((root/'migrations/sqlite/0029_oidc_global_logout.sql').read_text())
assert 'oidc_sub_hash' in {row[1] for row in c.execute('PRAGMA table_info(ce_user_sessions)')}
print('PASS 0029 upgrades existing session registry')
c.execute("INSERT INTO ce_oidc_logout_replay (jti_hash,issuer,expires_at) VALUES ('x','issuer',1111111111)")
try:
    c.execute("INSERT INTO ce_oidc_logout_replay (jti_hash,issuer,expires_at) VALUES ('x','issuer',1111111111)")
    raise AssertionError('duplicate logout notification was accepted')
except sqlite3.IntegrityError: pass
print('PASS database enforces one-time logout event')
c.commit()
c.execute('BEGIN')
c.execute("INSERT INTO ce_oidc_logout_replay (jti_hash,issuer,expires_at) VALUES ('y','issuer',1111111111)")
c.rollback()
assert c.execute("SELECT COUNT(*) FROM ce_oidc_logout_replay WHERE jti_hash='y'").fetchone()[0]==0
print('PASS atomic rollback on interrupted event')
assert c.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
print('PASS SQLite integrity')
