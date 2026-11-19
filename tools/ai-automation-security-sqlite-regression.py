#!/usr/bin/env python3
"""Execute 1.7.8->1.7.9 SQL migrations and lifecycle CAS acceptance on SQLite."""
from __future__ import annotations
import pathlib
import sqlite3
import tempfile
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[1]
MIGRATIONS = [
    '0001_initial_schema', '0031_ai_chat', '0037_ai_tools',
    '0040_ai_cost_control', '0041_ai_background_automation',
    '0042_ai_automation_delivery_acceptance',
]


def check() -> None:
    with tempfile.TemporaryDirectory(prefix='ce-automation-security-') as folder:
        db_file = pathlib.Path(folder) / 'qa.sqlite3'
        con = sqlite3.connect(str(db_file), isolation_level=None)
        con.row_factory = sqlite3.Row
        con.execute('PRAGMA foreign_keys = ON')
        for name in MIGRATIONS:
            con.executescript((ROOT / 'migrations' / 'sqlite' / (name + '.sql')).read_text())
        cols = {x['name'] for x in con.execute('PRAGMA table_info(ce_ai_automation_runs)')}
        assert 'last_offer_at' in cols
        owner, foreign, role = str(uuid.uuid4()), str(uuid.uuid4()), str(uuid.uuid4())
        for ident in (owner, foreign):
            con.execute('INSERT INTO ce_users (id,email,name,password_hash) VALUES (?,?,?,?)', (ident, ident+'@test.local', 'Test', 'hash'))
        con.execute('INSERT INTO ce_roles (id,handle,name,permissions) VALUES (?,?,?,?)', (role,'qa_automation','QA','["content:read","content:write"]'))
        con.execute('INSERT INTO ce_user_roles (user_id,role_id) VALUES (?,?)',(owner,role))
        automation=str(uuid.uuid4())
        con.execute('INSERT INTO ce_ai_automations (id,user_id,title,prompt,interval_minutes,next_run_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',
                    (automation,owner,'Security QA','Confidential prompt',60,1000,1,1))
        # Foreign-key owner isolation and uniqueness of periodic deliveries.
        try:
            con.execute('INSERT INTO ce_ai_automation_runs(id,automation_id,user_id,prompt_snapshot,scheduled_for,status,queued_at) VALUES (?,?,?,?,?,?,?)',
                        (str(uuid.uuid4()),str(uuid.uuid4()),owner,'X',42,'queued',1))
            raise AssertionError('orphan run was accepted')
        except sqlite3.IntegrityError:
            pass
        runs=[]
        for i in range(101):
            rid=str(uuid.uuid4()); runs.append(rid)
            con.execute('INSERT INTO ce_ai_automation_runs(id,automation_id,user_id,prompt_snapshot,scheduled_for,status,queued_at) VALUES (?,?,?,?,?,?,?)',
                        (rid,automation,owner,'Confidential prompt',None,'queued',10+i))
        try:
            con.execute('INSERT INTO ce_ai_automation_runs(id,automation_id,user_id,prompt_snapshot,scheduled_for,status,queued_at) VALUES (?,?,?,?,?,?,?)',
                        (str(uuid.uuid4()),automation,owner,'X',42,'queued',1))
            con.execute('INSERT INTO ce_ai_automation_runs(id,automation_id,user_id,prompt_snapshot,scheduled_for,status,queued_at) VALUES (?,?,?,?,?,?,?)',
                        (str(uuid.uuid4()),automation,owner,'X',42,'queued',2))
            raise AssertionError('duplicate periodic slot was accepted')
        except sqlite3.IntegrityError:
            pass
        con.execute("UPDATE ce_ai_automation_runs SET last_offer_at = 200 WHERE scheduled_for IS NOT NULL")
        con.execute("UPDATE ce_ai_automation_runs SET last_offer_at = 200 WHERE id IN ("+','.join('?' for _ in runs[:100])+')', runs[:100])
        chosen=[r['id'] for r in con.execute("SELECT id FROM ce_ai_automation_runs WHERE status='queued' ORDER BY COALESCE(last_offer_at,0),queued_at,id LIMIT 1")]
        assert chosen==[runs[100]], 'old offered runs must not starve an unoffered run'
        # Duplicated queue delivery cannot claim a terminal or already running run.
        cur=con.execute("UPDATE ce_ai_automation_runs SET status='running',started_at=100 WHERE id=? AND status='queued'",(runs[0],))
        assert cur.rowcount==1
        assert con.execute("UPDATE ce_ai_automation_runs SET status='running' WHERE id=? AND status='queued'",(runs[0],)).rowcount==0
        con.execute("UPDATE ce_ai_automation_runs SET status='failed',finished_at=200,error='Execution interrupted; provider may have charged' WHERE id=? AND status='running'",(runs[0],))
        assert con.execute("UPDATE ce_ai_automation_runs SET status='succeeded',conversation_id=?,finished_at=201,error=NULL WHERE id=? AND (status='running' OR (status='failed' AND error='Execution interrupted; provider may have charged'))",(str(uuid.uuid4()),runs[0])).rowcount==1
        assert con.execute("UPDATE ce_ai_automation_runs SET status='running' WHERE id=? AND status='queued'",(runs[0],)).rowcount==0
        # An expired queued run remains permanently non-executable.
        con.execute("UPDATE ce_ai_automation_runs SET queued_at=1 WHERE id=?",(runs[1],))
        con.execute("UPDATE ce_ai_automation_runs SET status='failed',finished_at=100000,error='Queue delivery deadline exceeded; no automatic replay' WHERE status='queued' AND queued_at<100")
        assert con.execute("UPDATE ce_ai_automation_runs SET status='running' WHERE id=? AND status='queued'",(runs[1],)).rowcount==0
        # Failed/canceled/completed prompt snapshots are expired, not sent to external systems.
        con.execute("UPDATE ce_ai_automation_runs SET finished_at=1 WHERE id=?",(runs[0],))
        cur=con.execute("UPDATE ce_ai_automation_runs SET prompt_snapshot='[expired]' WHERE status IN ('succeeded','failed','canceled') AND finished_at < ? AND prompt_snapshot <> '[expired]'", (3000000,))
        assert cur.rowcount>=2
        assert con.execute('SELECT prompt_snapshot FROM ce_ai_automation_runs WHERE id=?',(runs[0],)).fetchone()[0]=='[expired]'
        con.close()
    print('OK: AI Automation 1.7.9 SQLite schema, CAS, fairness, terminal-state safety, snapshot retention')


if __name__ == '__main__':
    check()
