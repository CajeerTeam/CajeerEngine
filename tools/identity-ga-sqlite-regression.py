#!/usr/bin/env python3
"""Execute Identity 1.6.0's actual SQL on SQLite without a PHP PDO extension."""
from pathlib import Path
import sqlite3
from datetime import datetime, timedelta, timezone

root = Path(__file__).resolve().parents[1]
db = sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript('''
CREATE TABLE ce_users(id TEXT PRIMARY KEY);
CREATE TABLE ce_api_tokens(id TEXT PRIMARY KEY, user_id TEXT NOT NULL,
    name TEXT NOT NULL, expires_at TEXT, revoked_at TEXT);
''')
for migration in ['0026_skif4er_oidc.sql', '0027_oidc_account_linking.sql',
                  '0028_unified_sessions.sql', '0029_oidc_global_logout.sql',
                  '0030_authentication_assurance.sql']:
    db.executescript((root / 'migrations/sqlite' / migration).read_text())
issuer = 'https://id.skif4er.ru/realms/skif4er'
user = '11111111-1111-4111-8111-111111111111'
other = '22222222-2222-4222-8222-222222222222'
expires = (datetime.now(timezone.utc) + timedelta(days=1)).strftime('%Y-%m-%d %H:%M:%S')
for u in [user, other]:
    db.execute('INSERT INTO ce_users(id) VALUES (?)', (u,))
db.execute('INSERT INTO ce_oidc_identities(issuer,subject,user_id) VALUES (?,?,?)', (issuer, 'subject', user))
records = [
    ('local', user, 'admin-session', 'local'),
    ('sso1', user, 'admin-session', 'skif4er'),
    ('sso2', user, 'admin-session', 'skif4er'),
    ('api', user, 'integration-api', None),
    ('other', other, 'admin-session', 'skif4er'),
]
for token_id, uid, name, provider in records:
    db.execute('INSERT INTO ce_api_tokens(id,user_id,name,expires_at) VALUES (?,?,?,?)', (token_id, uid, name, expires))
    if provider:
        db.execute('INSERT INTO ce_user_sessions(token_id,user_id,provider,expires_at) VALUES (?,?,?,?)',
                   (token_id, uid, provider, expires))
# SQL now used by OidcStore::completeLink: both registries must be live.
link_sql = '''SELECT t.id FROM ce_api_tokens t
 JOIN ce_user_sessions s ON s.token_id=t.id AND s.user_id=t.user_id
 WHERE t.id=:id AND t.user_id=:user AND t.name='admin-session'
 AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at>CURRENT_TIMESTAMP)
 AND s.revoked_at IS NULL AND (s.expires_at IS NULL OR s.expires_at>CURRENT_TIMESTAMP)
 AND s.last_seen_at >= :cutoff'''
assert db.execute(link_sql, {'id':'local','user':user,'cutoff':(datetime.now(timezone.utc)-timedelta(hours=12)).strftime('%Y-%m-%d %H:%M:%S')}).fetchone() == ('local',)
db.execute('UPDATE ce_user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE token_id=?', ('local',))
assert db.execute(link_sql, {'id':'local','user':user,'cutoff':(datetime.now(timezone.utc)-timedelta(hours=12)).strftime('%Y-%m-%d %H:%M:%S')}).fetchone() is None
assert db.execute('SELECT revoked_at FROM ce_api_tokens WHERE id=?', ('local',)).fetchone()[0] is None
# Real bulk revocation SQL, preserving local/integration credentials.
revoke_tokens = '''UPDATE ce_api_tokens SET revoked_at = CURRENT_TIMESTAMP
 WHERE user_id = :uid AND name = 'admin-session' AND revoked_at IS NULL
 AND id IN (SELECT token_id FROM ce_user_sessions WHERE user_id=:owner
 AND provider='skif4er' AND revoked_at IS NULL)'''
revoke_sessions = '''UPDATE ce_user_sessions SET revoked_at = CURRENT_TIMESTAMP
 WHERE user_id=:uid AND provider='skif4er' AND revoked_at IS NULL'''
def unlink():
    with db:
        db.execute('DELETE FROM ce_oidc_identities WHERE issuer=? AND user_id=?', (issuer,user))
        db.execute(revoke_tokens, {'uid':user,'owner':user})
        db.execute(revoke_sessions, {'uid':user})
# A failed token-store mutation cannot leave an unlinked account with a live SSO cookie.
db.commit()  # fixture is an independent committed state before the rollback scenario
db.execute('''CREATE TRIGGER reject_revocation BEFORE UPDATE OF revoked_at ON ce_api_tokens
 WHEN OLD.id='sso1' BEGIN SELECT RAISE(ABORT,'simulated database fault'); END''')
try:
    unlink()
    raise AssertionError('failed revocation committed unlink')
except sqlite3.DatabaseError:
    assert db.execute('SELECT COUNT(*) FROM ce_oidc_identities').fetchone()[0] == 1
db.execute('DROP TRIGGER reject_revocation')
unlink()
assert db.execute('SELECT COUNT(*) FROM ce_oidc_identities').fetchone()[0] == 0
for token_id in ('sso1','sso2'):
    assert db.execute('SELECT revoked_at FROM ce_api_tokens WHERE id=?', (token_id,)).fetchone()[0]
    assert db.execute('SELECT revoked_at FROM ce_user_sessions WHERE token_id=?', (token_id,)).fetchone()[0]
for token_id in ('local','api','other'):
    assert db.execute('SELECT revoked_at FROM ce_api_tokens WHERE id=?', (token_id,)).fetchone()[0] is None
assert db.execute('SELECT revoked_at FROM ce_user_sessions WHERE token_id=?', ('other',)).fetchone()[0] is None
assert db.execute('PRAGMA integrity_check').fetchone()[0] == 'ok'
print('Identity 1.6.0 GA SQLite: callback registry, atomic rollback, selective SSO revocation, unaffected API/local/other sessions PASS')
