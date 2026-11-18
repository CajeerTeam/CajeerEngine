#!/usr/bin/env python3
"""Actual SQLite session migration, isolation, atomic revoke and rotation invariants."""
from pathlib import Path
import sqlite3
r=Path(__file__).resolve().parents[1]
c=sqlite3.connect(':memory:')
c.execute('PRAGMA foreign_keys=ON')
c.executescript('''CREATE TABLE ce_users(id TEXT PRIMARY KEY);
CREATE TABLE ce_api_tokens(id TEXT PRIMARY KEY,user_id TEXT REFERENCES ce_users(id),name TEXT,revoked_at TEXT,expires_at TEXT);''')
c.executescript((r/'migrations/sqlite/0028_unified_sessions.sql').read_text())
a='12345678-1234-1234-1234-123456789abc';b='12345678-1234-1234-1234-123456789def'
for uid in (a,b):c.execute('INSERT INTO ce_users VALUES (?)',(uid,))
for tid,uid,name in [('a1',a,'admin-session'),('a2',a,'admin-session'),('b1',b,'admin-session'),('api',a,'integration')]:
 c.execute('INSERT INTO ce_api_tokens (id,user_id,name) VALUES (?,?,?)',(tid,uid,name))
for tid,uid,provider in [('a1',a,'local'),('a2',a,'skif4er'),('b1',b,'local')]:
 c.execute('INSERT INTO ce_user_sessions (token_id,user_id,provider) VALUES (?,?,?)',(tid,uid,provider))
assert c.execute('SELECT count(*) FROM ce_user_sessions').fetchone()[0]==3
print('PASS actual SQLite 0028 migration and local/OIDC session metadata')
try: c.execute("INSERT INTO ce_user_sessions (token_id,user_id,provider) VALUES ('api',?,'invalid')",(a,));raise AssertionError('invalid provider accepted')
except sqlite3.IntegrityError: pass
try: c.execute("INSERT INTO ce_user_sessions (token_id,user_id,provider) VALUES ('a1',?,'local')",(b,));raise AssertionError('duplicate credential accepted')
except sqlite3.IntegrityError: pass
print('PASS provider constraint and unique credential')
c.commit()
# Same transaction as application code: revoking a's credential cannot revoke b's credential.
c.execute('BEGIN')
affected=c.execute("UPDATE ce_api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=? AND name='admin-session' AND revoked_at IS NULL AND EXISTS (SELECT 1 FROM ce_user_sessions WHERE token_id=? AND user_id=? AND revoked_at IS NULL)",('b1',a,'b1',a)).rowcount
assert affected==0
c.rollback()
c.execute('BEGIN')
assert c.execute("UPDATE ce_api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=? AND name='admin-session' AND revoked_at IS NULL AND EXISTS (SELECT 1 FROM ce_user_sessions WHERE token_id=? AND user_id=? AND revoked_at IS NULL)",('a2',a,'a2',a)).rowcount==1
assert c.execute('UPDATE ce_user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE token_id=? AND user_id=? AND revoked_at IS NULL',('a2',a)).rowcount==1
c.commit()
assert c.execute('SELECT revoked_at IS NULL FROM ce_api_tokens WHERE id=?',('b1',)).fetchone()==(1,)
assert c.execute('SELECT revoked_at IS NULL FROM ce_api_tokens WHERE id=?',('api',)).fetchone()==(1,)
print('PASS selective revoke, cross-account isolation, independent API token preserved')
# A failed rotation must leave original credential live.
c.execute('BEGIN')
c.execute('INSERT INTO ce_api_tokens (id,user_id,name) VALUES (?,?,?)',('new',a,'admin-session'))
c.execute('INSERT INTO ce_user_sessions (token_id,user_id,provider) VALUES (?,?,?)',('new',a,'local'))
c.rollback()
assert c.execute('SELECT revoked_at FROM ce_api_tokens WHERE id=?',('a1',)).fetchone()==(None,)
assert c.execute("SELECT count(*) FROM ce_api_tokens WHERE id='new'").fetchone()[0]==0
c.execute('BEGIN')
c.execute('INSERT INTO ce_api_tokens (id,user_id,name) VALUES (?,?,?)',('new',a,'admin-session'))
c.execute('INSERT INTO ce_user_sessions (token_id,user_id,provider) VALUES (?,?,?)',('new',a,'local'))
c.execute('UPDATE ce_api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE id=?',('a1',))
c.execute('UPDATE ce_user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE token_id=?',('a1',))
c.commit()
assert c.execute('SELECT revoked_at IS NULL FROM ce_api_tokens WHERE id=?',('new',)).fetchone()==(1,)
assert c.execute('SELECT revoked_at IS NOT NULL FROM ce_api_tokens WHERE id=?',('a1',)).fetchone()==(1,)
assert c.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
print('PASS transactional credential rotation, rollback and SQLite integrity')
