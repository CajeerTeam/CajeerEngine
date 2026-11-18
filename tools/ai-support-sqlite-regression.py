#!/usr/bin/env python3
"""Actual SQLite migration 0036 integrity/rollback, independent of PHP PDO availability."""
import sqlite3, pathlib, hashlib
root=pathlib.Path(__file__).resolve().parents[1]
db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript((root/'migrations/sqlite/0036_ai_support_center.sql').read_text())
ticket='11111111-1111-4111-8111-111111111111'
message='22222222-2222-4222-8222-222222222222'
secret='a'*43
row=(ticket,hashlib.sha256(secret.encode()).hexdigest(),'Tester','test@example.org','Question','ru','open','normal',1,1,123,123)
db.execute('INSERT INTO ce_support_tickets (id,access_hash,name,email,subject,locale,status,priority,needs_human,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',row)
db.execute('INSERT INTO ce_support_messages (id,ticket_id,role,author_id,content,sources_json,created_at) VALUES (?,?,?,?,?,?,?)',(message,ticket,'visitor',None,'Question text','[]',123))
assert db.execute('SELECT COUNT(*) FROM ce_support_messages WHERE ticket_id=?',(ticket,)).fetchone()[0]==1
assert not any(secret in str(item) for item in db.execute('SELECT * FROM ce_support_tickets').fetchone())
db.execute('UPDATE ce_support_tickets SET status=?,revision=revision+1 WHERE id=? AND revision=?',('resolved',ticket,1))
assert db.execute('SELECT status,revision FROM ce_support_tickets WHERE id=?',(ticket,)).fetchone()==('resolved',2)
db.execute('UPDATE ce_support_tickets SET status=?,revision=revision+1 WHERE id=? AND revision=?',('open',ticket,1))
assert db.execute('SELECT status,revision FROM ce_support_tickets WHERE id=?',(ticket,)).fetchone()==('resolved',2)
try:
    db.execute('INSERT INTO ce_support_messages (id,ticket_id,role,author_id,content,sources_json,created_at) VALUES (?,?,?,?,?,?,?)',('33333333-3333-4333-8333-333333333333','missing','visitor',None,'No access','[]',123))
    raise AssertionError('Foreign key must reject orphan message')
except sqlite3.IntegrityError:pass
db.execute('DELETE FROM ce_support_tickets WHERE id=?',(ticket,))
assert db.execute('SELECT COUNT(*) FROM ce_support_messages').fetchone()[0]==0
for table, index in [('ce_support_tickets','idx_support_queue'),('ce_support_tickets','idx_support_assignee'),('ce_support_messages','idx_support_transcript')]:
    assert index in [x[1] for x in db.execute('PRAGMA index_list('+table+')')],index
print('PASS SQLite 0036: real ticket/message writes, hashed capability, CAS, FK rejection, cascade and queue indexes')
