#!/usr/bin/env python3
"""Real SQLite transaction checks complement required PHP/PDO integration in release CI."""
import pathlib
import sqlite3

root=pathlib.Path(__file__).resolve().parents[1]
c=sqlite3.connect(':memory:')
c.executescript((root/'migrations/sqlite/0040_ai_cost_control.sql').read_text())
user='00000000-0000-4000-8000-000000000001'
period='2026-11';day='2026-11-19';scope='user:'+user
c.execute('INSERT INTO ce_ai_cost_balances VALUES (?,?,0,0)',(scope,period))
c.execute('INSERT INTO ce_ai_cost_balances VALUES (?,?,0,0)',('global',period))
c.commit()
# Hard cap CAS is applied before any provider request: two reservations cannot both win.
for amount in [7000,4000]:
    with c:
        c.execute('UPDATE ce_ai_cost_balances SET reserved_micro_usd=reserved_micro_usd+? WHERE scope=? AND period=? AND used_micro_usd+reserved_micro_usd+?<=?',(amount,scope,period,amount,10000))
assert c.execute('SELECT reserved_micro_usd FROM ce_ai_cost_balances WHERE scope=?',(scope,)).fetchone()[0]==7000
# SQLite compares a numeric arithmetic expression to a TEXT-bound threshold by
# storage class, accepting an over-budget reservation. PDO must bind PARAM_INT.
assert c.execute('SELECT (7000 + ?) <= ?', ('4000', '10000')).fetchone()[0] == 1
assert c.execute('SELECT (7000 + ?) <= ?', (4000, 10000)).fetchone()[0] == 0
# Atomic ledger settlement, no partial records after failure.
try:
    with c:
        c.execute('UPDATE ce_ai_cost_balances SET reserved_micro_usd=reserved_micro_usd-7000, used_micro_usd=used_micro_usd+3000 WHERE scope=? AND period=?',(scope,period))
        c.execute('INSERT INTO ce_ai_cost_events VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',('id',user,'chat','gpt-4.1-mini',day,period,800,100,500,3000,40,10,160,'measured',1))
        raise RuntimeError('simulated post-OpenAI DB failure')
except RuntimeError: pass
assert c.execute('SELECT used_micro_usd,reserved_micro_usd FROM ce_ai_cost_balances WHERE scope=?',(scope,)).fetchone()==(0,7000)
assert c.execute('SELECT COUNT(*) FROM ce_ai_cost_events').fetchone()[0]==0
with c:
    c.execute('UPDATE ce_ai_cost_balances SET reserved_micro_usd=reserved_micro_usd-7000,used_micro_usd=used_micro_usd+3000 WHERE scope=? AND period=?',(scope,period))
    c.execute('INSERT INTO ce_ai_cost_events VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',('id',user,'chat','gpt-4.1-mini',day,period,800,100,500,3000,40,10,160,'measured',1))
try:
    with c:
        c.execute('INSERT INTO ce_ai_cost_events VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',('id',user,'chat','gpt-4.1-mini',day,period,800,100,500,3000,40,10,160,'measured',1))
except sqlite3.IntegrityError: pass
else: raise AssertionError('Duplicate provider settlement was accepted')
assert c.execute('SELECT used_micro_usd,reserved_micro_usd FROM ce_ai_cost_balances WHERE scope=?',(scope,)).fetchone()==(3000,0)
assert c.execute('SELECT COUNT(*) FROM ce_ai_cost_events').fetchone()[0]==1
print('PASS actual SQLite hard cap CAS, rollback, exact-once cost ledger and balances')
