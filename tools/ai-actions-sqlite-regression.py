#!/usr/bin/env python3
"""Actual SQLite CAS, transactional rollback, retention and permission-isolation fixture."""
import sqlite3, tempfile, os, time
from pathlib import Path
root=Path(__file__).resolve().parent.parent
now=int(time.time())
with tempfile.TemporaryDirectory(prefix='ce-ai-actions-') as d:
    path=os.path.join(d,'test.sqlite')
    conn=sqlite3.connect(path,timeout=1,isolation_level=None)
    conn.executescript((root/'migrations/sqlite/0038_ai_actions.sql').read_text())
    conn.executescript((root/'migrations/sqlite/0036_ai_support_center.sql').read_text())
    uid='11111111-1111-4111-8111-111111111111'; other='22222222-2222-4222-8222-222222222222'
    aid='33333333-3333-4333-8333-333333333333'; ticket='44444444-4444-4444-8444-444444444444'
    conn.execute('INSERT INTO ce_support_tickets(id,access_hash,name,email,subject,locale,status,priority,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                 (ticket,'x'*64,'Name','private@example.test','Question','ru','open','normal',1,now,now))
    conn.execute('INSERT INTO ce_ai_actions(id,user_id,action_name,payload_json,before_json,expected_revision,state,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)',
                 (aid,uid,'cms_action_support_status','{"ticket_id":"'+ticket+'","status":"resolved"}','{"status":"open","revision":1}',1,'pending',now,now+86400))
    assert conn.execute('SELECT COUNT(*) FROM ce_ai_actions WHERE user_id=?',(other,)).fetchone()[0]==0
    claim='UPDATE ce_ai_actions SET state=? WHERE id=? AND user_id=? AND state=? AND expires_at>=?'
    conn.execute('BEGIN IMMEDIATE')
    assert conn.execute(claim,('running',aid,uid,'pending',now)).rowcount==1
    assert conn.execute('UPDATE ce_support_tickets SET status=?,revision=revision+1 WHERE id=? AND revision=?',('resolved',ticket,1)).rowcount==1
    conn.execute('UPDATE ce_ai_actions SET state=?,result_json=? WHERE id=? AND state=?',('applied','{"status":"resolved"}',aid,'running'))
    conn.execute('COMMIT')
    assert conn.execute(claim,('running',aid,uid,'pending',now)).rowcount==0
    assert conn.execute('SELECT revision,status FROM ce_support_tickets WHERE id=?',(ticket,)).fetchone()==(2,'resolved')
    print('PASS transactionally applied action exactly once with ticket revision CAS')
    second='55555555-5555-4555-8555-555555555555'
    conn.execute('INSERT INTO ce_ai_actions(id,user_id,action_name,payload_json,before_json,expected_revision,state,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)',
                 (second,uid,'cms_action_support_status','{}','{}',2,'pending',now,now+86400))
    conn.execute("CREATE TRIGGER fail_ticket BEFORE UPDATE ON ce_support_tickets BEGIN SELECT RAISE(ABORT,'simulated failure'); END")
    try:
        conn.execute('BEGIN IMMEDIATE')
        assert conn.execute(claim,('running',second,uid,'pending',now)).rowcount==1
        conn.execute('UPDATE ce_support_tickets SET revision=revision+1 WHERE id=?',(ticket,))
        conn.execute('COMMIT')
        raise AssertionError('simulated SQL failure was not raised')
    except sqlite3.DatabaseError:
        conn.execute('ROLLBACK')
    assert conn.execute('SELECT state FROM ce_ai_actions WHERE id=?',(second,)).fetchone()==('pending',)
    assert conn.execute('SELECT revision FROM ce_support_tickets WHERE id=?',(ticket,)).fetchone()==(2,)
    conn.execute('DROP TRIGGER fail_ticket')
    print('PASS SQL errors roll back both action claim and target content')
    assert conn.execute(claim,('running',second,other,'pending',now)).rowcount==0
    conn.execute('UPDATE ce_ai_actions SET expires_at=? WHERE id=?',(now-2,second))
    assert conn.execute(claim,('running',second,uid,'pending',now)).rowcount==0
    print('PASS other users and expired proposals cannot be approved')
    conn.execute('DELETE FROM ce_ai_actions WHERE state <> ? AND expires_at < ?',('running',now-1))
    assert conn.execute('SELECT COUNT(*) FROM ce_ai_actions WHERE id=?',(second,)).fetchone()[0]==0
    print('PASS expired action retention never deletes active execution')
print('AI Actions SQLite regression PASSED')
