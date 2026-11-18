#!/usr/bin/env python3
"""Execute the GA ticket CAS and active RAG-retention SQL against actual SQLite."""
import sqlite3
from pathlib import Path

root = Path(__file__).resolve().parent.parent
conn = sqlite3.connect(':memory:')
conn.execute('PRAGMA foreign_keys=ON')
for name in ('0031_ai_chat','0032_ai_content_editor','0035_ai_knowledge_chat','0036_ai_support_center'):
    conn.executescript((root / 'migrations/sqlite' / (name + '.sql')).read_text())
user = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
ticket = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'
now = 1800000000
old = now - 100 * 86400
conn.execute('INSERT INTO ce_support_tickets '
             '(id, access_hash, name, email, subject, locale, status, priority, needs_human, revision, created_at, updated_at) '
             'VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
             (ticket, 'x'*64, 'Client', 'client@example.test', 'Question', 'ru', 'open', 'normal', 1, 1, old, old))
conn.commit()
cas_guest = ('UPDATE ce_support_tickets SET status=?,needs_human=1,revision=revision+1,updated_at=? '
             'WHERE id=? AND revision=? AND status<>?')
cas_operator = ('UPDATE ce_support_tickets SET status=?,needs_human=0,assigned_to=?,revision=revision+1,updated_at=? '
                'WHERE id=? AND revision=? AND status<>?')
assert conn.execute(cas_guest, ('open', now, ticket, 1, 'closed')).rowcount == 1
assert conn.execute(cas_operator, ('waiting', user, now, ticket, 1, 'closed')).rowcount == 0
assert conn.execute(cas_operator, ('waiting', user, now, ticket, 2, 'closed')).rowcount == 1
conn.execute("UPDATE ce_support_tickets SET status='closed' WHERE id=?", (ticket,))
assert conn.execute(cas_guest, ('open', now, ticket, 3, 'closed')).rowcount == 0
assert conn.execute(cas_operator, ('waiting', user, now, ticket, 3, 'closed')).rowcount == 0
conn.commit()
print('PASS guest/operator replies use SQL revision CAS and cannot reopen closed tickets')
# Atomic update must rollback when the following message insertion fails.
conn.execute("UPDATE ce_support_tickets SET status='open', revision=4 WHERE id=?", (ticket,))
conn.execute("CREATE TRIGGER abort_insert BEFORE INSERT ON ce_support_messages BEGIN SELECT RAISE(ABORT, 'fixture'); END")
conn.commit()
try:
    conn.execute('BEGIN')
    assert conn.execute(cas_operator, ('waiting', user, now, ticket, 4, 'closed')).rowcount == 1
    conn.execute('INSERT INTO ce_support_messages (id,ticket_id,role,content,sources_json,created_at) VALUES (?,?,?,?,?,?)',
                 ('11111111-1111-4111-8111-111111111111', ticket, 'operator', 'reply', '[]', now))
    raise AssertionError('expected database failure')
except sqlite3.IntegrityError:
    conn.rollback()
assert conn.execute('SELECT revision FROM ce_support_tickets WHERE id=?', (ticket,)).fetchone()[0] == 4
print('PASS ticket revision update rolls back if the message cannot be persisted')
rag = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'
job = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'
conn.execute('INSERT INTO ce_ai_kb_conversations (id,user_id,title,visibility,locale,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',
             (rag,user,'RAG','public','ru',old,old))
conn.execute('INSERT INTO ce_ai_editor_jobs (id,user_id,period,reserved_tokens,expires_at) VALUES (?,?,?,?,?)',
             (job,user,'2026-11',100,now+120))
condition = ('updated_at < ? AND NOT EXISTS (SELECT 1 FROM ce_ai_editor_jobs j '
             'WHERE j.user_id = ce_ai_kb_conversations.user_id AND j.expires_at >= ?)')
assert conn.execute('SELECT id FROM ce_ai_kb_conversations WHERE '+condition, (now-90*86400,now)).fetchall()==[]
conn.execute('UPDATE ce_ai_editor_jobs SET expires_at=? WHERE id=?',(now-1,job))
assert conn.execute('SELECT id FROM ce_ai_kb_conversations WHERE '+condition,(now-90*86400,now)).fetchall()==[(rag,)]
print('PASS retention preserves active RAG requests and reclaims expired generation leases')
print('AI GA SQLite hardening PASSED')
