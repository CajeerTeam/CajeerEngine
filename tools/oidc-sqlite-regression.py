#!/usr/bin/env python3
"""Real SQLite upgrade/uniqueness and one-time transaction fixture for 0026."""
import sqlite3
from pathlib import Path

root = Path(__file__).resolve().parent.parent
sql = (root / 'migrations/sqlite/0026_skif4er_oidc.sql').read_text()
con = sqlite3.connect(':memory:', isolation_level=None)
con.executescript('CREATE TABLE ce_users(id TEXT PRIMARY KEY, status TEXT);')
con.execute("INSERT INTO ce_users VALUES (?, ?)", ('12345678-1234-1234-1234-123456789abc','active'))
con.executescript(sql)
con.execute('INSERT INTO ce_oidc_identities (issuer,subject,user_id) VALUES (?,?,?)', ('https://id.skif4er.ru/realms/skif4er','sub-one','12345678-1234-1234-1234-123456789abc'))
try:
    con.execute('INSERT INTO ce_oidc_identities (issuer,subject,user_id) VALUES (?,?,?)', ('https://id.skif4er.ru/realms/skif4er','sub-one','another'))
    raise AssertionError('Duplicate subject accepted')
except sqlite3.IntegrityError:
    pass
con.execute('INSERT INTO ce_oidc_transactions(state_hash,browser_hash,nonce,verifier_ciphertext,expires_at) VALUES (?,?,?,?,?)', ('a'*64,'b'*64,'nonce','encrypted',9999999999))
con.execute('BEGIN')
value = con.execute('SELECT nonce FROM ce_oidc_transactions WHERE state_hash=? AND browser_hash=?', ('a'*64,'b'*64)).fetchone()
assert value == ('nonce',)
assert con.execute('DELETE FROM ce_oidc_transactions WHERE state_hash=? AND browser_hash=?', ('a'*64,'b'*64)).rowcount == 1
con.execute('COMMIT')
assert con.execute('DELETE FROM ce_oidc_transactions WHERE state_hash=? AND browser_hash=?', ('a'*64,'b'*64)).rowcount == 0
assert con.execute('PRAGMA integrity_check').fetchone() == ('ok',)
print('OIDC SQLite: upgrade, unique links, atomic consumption, replay rejection, integrity PASS')
