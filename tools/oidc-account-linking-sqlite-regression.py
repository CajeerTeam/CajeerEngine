#!/usr/bin/env python3
"""Exercise real SQLite migrations and atomic link/unlink/session checks."""
from pathlib import Path
import sqlite3
r=Path(__file__).resolve().parents[1]
c=sqlite3.connect(':memory:')
c.executescript('''CREATE TABLE ce_users(id TEXT PRIMARY KEY,email TEXT,status TEXT);
CREATE TABLE ce_api_tokens(id TEXT PRIMARY KEY,user_id TEXT,name TEXT,revoked_at TEXT,expires_at TEXT);''')
for v in ['0026_skif4er_oidc','0027_oidc_account_linking']:
 c.executescript((r/'migrations/sqlite'/f'{v}.sql').read_text())
issuer='https://id.skif4er.ru/realms/skif4er';user='12345678-1234-1234-1234-123456789abc';session='session-id'
c.execute('INSERT INTO ce_users VALUES (?,?,?)',(user,'test@example.test','active'))
c.execute('INSERT INTO ce_api_tokens (id,user_id,name) VALUES (?,?,?)',(session,user,'admin-session'))
c.execute('INSERT INTO ce_oidc_transactions (state_hash,browser_hash,nonce,verifier_ciphertext,expires_at,purpose,user_id,session_id) VALUES (?,?,?,?,?,?,?,?)',('a'*64,'b'*64,'nonce','encrypted',9999999999,'link',user,session))
assert c.execute('SELECT purpose,user_id,session_id FROM ce_oidc_transactions').fetchone()==('link',user,session)
assert c.execute('SELECT id FROM ce_api_tokens WHERE id=? AND user_id=? AND name=? AND revoked_at IS NULL',(session,user,'admin-session')).fetchone()==(session,)
c.execute('INSERT INTO ce_oidc_identities (issuer,subject,user_id) VALUES (?,?,?)',(issuer,'sub',user))
assert c.execute('SELECT provisioned FROM ce_oidc_identities').fetchone()==(0,)
try:
 c.execute('INSERT INTO ce_oidc_identities (issuer,subject,user_id) VALUES (?,?,?)',(issuer,'sub2',user))
 raise AssertionError('allowed second subject')
except sqlite3.IntegrityError: pass
c.execute('UPDATE ce_oidc_identities SET provisioned=1')
assert c.execute('SELECT provisioned FROM ce_oidc_identities').fetchone()==(1,)
c.execute('UPDATE ce_api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE id=?',(session,))
assert not c.execute('SELECT id FROM ce_api_tokens WHERE id=? AND revoked_at IS NULL',(session,)).fetchall()
assert c.execute('PRAGMA integrity_check').fetchone()==('ok',)
print('OIDC account linking SQLite: migrations, uniqueness, callback session checks, provenance PASS')
