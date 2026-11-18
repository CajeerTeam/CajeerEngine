#!/usr/bin/env python3
"""SQLite execution of the 1.6.9 SQL CAS / purge invariants (no PHP PDO needed)."""
import sqlite3
from pathlib import Path
from tempfile import TemporaryDirectory

root = Path(__file__).resolve().parent.parent
with TemporaryDirectory(prefix='ce-ai-resilience-') as temp:
    db = sqlite3.connect(str(Path(temp) / 'data.sqlite3'))
    db.execute('PRAGMA foreign_keys=ON')
    for migration in ('0031_ai_chat', '0032_ai_content_editor', '0033_ai_translation_studio',
                      '0035_ai_knowledge_chat', '0036_ai_support_center'):
        db.executescript((root / 'migrations/sqlite' / (migration + '.sql')).read_text())
    ticket = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
    old = 1_700_000_000
    now = old + 100 * 86400
    db.execute('INSERT INTO ce_support_tickets '
               '(id,access_hash,name,email,subject,locale,status,priority,needs_human,revision,created_at,updated_at) '
               'VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
               (ticket, 'b' * 64, 'Customer', 'customer@example.org', 'Billing question',
                'ru', 'open', 'normal', 1, 1, old, old))
    db.commit()
    # Simulate competing worker retry: a second revision-1 claim cannot win.
    claim = ('UPDATE ce_support_tickets SET status=?,needs_human=0,revision=revision+1,updated_at=? '
             'WHERE id=? AND revision=1 AND status=? AND needs_human=1')
    first = db.execute(claim, ('waiting', now, ticket, 'open'))
    assert first.rowcount == 1
    db.execute('INSERT INTO ce_support_messages (id,ticket_id,role,content,sources_json,created_at) '
               'VALUES (?,?,?,?,?,?)', ('11111111-1111-4111-8111-111111111111', ticket,
               'ai', 'Cited public answer', '[]', now))
    second = db.execute(claim, ('waiting', now, ticket, 'open'))
    assert second.rowcount == 0
    assert db.execute('SELECT COUNT(*) FROM ce_support_messages').fetchone()[0] == 1
    db.commit()
    print('PASS repeat/competing auto-reply cannot append a second AI message')
    # If inserting the AI message fails after claiming, the update must also roll back.
    other = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'
    db.execute('INSERT INTO ce_support_tickets '
               '(id,access_hash,name,email,subject,locale,status,priority,needs_human,revision,created_at,updated_at) '
               'VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
               (other, 'c' * 64, 'Customer', 'customer@example.org', 'Other issue', 'ru',
                'open', 'normal', 1, 1, old, old))
    db.commit()
    db.execute("CREATE TRIGGER reject_ai_message BEFORE INSERT ON ce_support_messages "
               "WHEN NEW.ticket_id = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' "
               "BEGIN SELECT RAISE(ABORT, 'insertion failed'); END")
    try:
        db.execute('BEGIN')
        assert db.execute(claim, ('waiting', now, other, 'open')).rowcount == 1
        db.execute('INSERT INTO ce_support_messages (id,ticket_id,role,content,sources_json,created_at) '
                   'VALUES (?,?,?,?,?,?)', ('22222222-2222-4222-8222-222222222222', other, 'ai', 'answer', '[]', now))
        raise AssertionError('insert must fail')
    except sqlite3.IntegrityError:
        db.rollback()
    assert db.execute('SELECT revision FROM ce_support_tickets WHERE id=?', (other,)).fetchone()[0] == 1
    print('PASS SQL error rolls back auto-reply claim and preserves ticket for operator')
    # Close one old ticket; leave the new/active tickets untouched by age-based pruning.
    db.execute("UPDATE ce_support_tickets SET status='closed',updated_at=? WHERE id=?", (old, other))
    db.commit()
    cutoff = now - 90 * 86400
    eligible = db.execute('SELECT id FROM ce_support_tickets WHERE status=? AND updated_at<? ORDER BY id LIMIT ?',
                          ('closed', cutoff, 100)).fetchall()
    assert eligible == [(other,)]
    # Deliberately fail between child deletion and parent deletion to validate rollback.
    db.execute("CREATE TRIGGER reject_ticket_delete BEFORE DELETE ON ce_support_tickets "
               "BEGIN SELECT RAISE(ABORT, 'simulated SQL outage'); END")
    try:
        db.execute('BEGIN')
        db.execute('DELETE FROM ce_support_messages WHERE ticket_id=?', (other,))
        db.execute('DELETE FROM ce_support_tickets WHERE id=? AND status=? AND updated_at<?',
                   (other, 'closed', cutoff))
        raise AssertionError('trigger must refuse deletion')
    except sqlite3.IntegrityError:
        db.rollback()
    assert db.execute('SELECT COUNT(*) FROM ce_support_tickets').fetchone()[0] == 2
    db.execute('DROP TRIGGER reject_ticket_delete')
    db.execute('BEGIN')
    db.execute('DELETE FROM ce_support_messages WHERE ticket_id=?', (other,))
    assert db.execute('DELETE FROM ce_support_tickets WHERE id=? AND status=? AND updated_at<?',
                      (other, 'closed', cutoff)).rowcount == 1
    db.commit()
    assert db.execute('SELECT COUNT(*) FROM ce_support_tickets').fetchone()[0] == 1
    assert db.execute('SELECT COUNT(*) FROM ce_support_messages').fetchone()[0] == 1
    print('PASS bounded retention selects closed old tickets, rolls back errors and preserves active support')
    print('AI SQL resilience regression PASSED')
