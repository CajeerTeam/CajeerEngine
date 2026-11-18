#!/usr/bin/env python3
"""Real SQLite migration, per-session assurance and transaction rollback checks."""
from pathlib import Path
import sqlite3,time
root=Path(__file__).resolve().parents[1]
db=sqlite3.connect(':memory:');db.execute('PRAGMA foreign_keys=ON')
db.executescript('CREATE TABLE ce_users (id TEXT PRIMARY KEY); CREATE TABLE ce_api_tokens (id TEXT PRIMARY KEY,user_id TEXT,name TEXT,revoked_at TEXT,expires_at TEXT);')
for fn in ['0028_unified_sessions.sql','0029_oidc_global_logout.sql','0030_authentication_assurance.sql']:
    db.executescript((root/'migrations/sqlite'/fn).read_text())
cols={r[1] for r in db.execute('PRAGMA table_info(ce_user_sessions)')}
assert {'assured_at','assurance_mfa','assurance_method'} <= cols
print('PASS 0030 upgrades previous SQLite session registry')
uid='11111111-1111-4111-8111-111111111111';uid2='22222222-2222-4222-8222-222222222222'
db.executemany('INSERT INTO ce_users(id) VALUES (?)',[(uid,),(uid2,)])
for sid,who,provider in [('local',uid,'local'),('sso',uid,'skif4er'),('other',uid2,'local')]:
 db.execute('INSERT INTO ce_api_tokens (id,user_id,name) VALUES (?, ?, ?)',(sid,who,'admin-session'))
 db.execute('INSERT INTO ce_user_sessions (token_id,user_id,provider) VALUES (?,?,?)',(sid,who,provider))
now=int(time.time())
query="UPDATE ce_user_sessions SET assured_at = ?, assurance_method = ?, assurance_mfa = ? WHERE token_id = ? AND user_id = ? AND revoked_at IS NULL AND EXISTS (SELECT 1 FROM ce_api_tokens t WHERE t.id = ce_user_sessions.token_id AND t.user_id = ? AND t.name = 'admin-session' AND t.revoked_at IS NULL)"
assert db.execute(query,(now,'local',0,'other',uid,uid)).rowcount==0
assert db.execute(query,(now,'local',0,'local',uid,uid)).rowcount==1
assert db.execute('SELECT assured_at FROM ce_user_sessions WHERE token_id="other"').fetchone()[0] is None
print('PASS assurance cannot be stamped on another user session')
db.execute('UPDATE ce_api_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE id = "local"')
assert db.execute(query,(now,'local',1,'local',uid,uid)).rowcount==0
print('PASS revoked token cannot renew assurance')
db.commit()
db.execute('BEGIN')
db.execute(query,(now,'oidc',1,'sso',uid,uid))
db.rollback()
assert db.execute('SELECT assured_at FROM ce_user_sessions WHERE token_id="sso"').fetchone()[0] is None
print('PASS failed transaction leaves no assurance evidence')
assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
print('PASS SQLite integrity')
