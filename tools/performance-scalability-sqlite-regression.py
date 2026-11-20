#!/usr/bin/env python3
"""Execute the ACTUAL PHP-generated bounded RAG SQL on SQLite, verifying per-term limits and privacy."""
import json
import pathlib
import sqlite3
import subprocess

root = pathlib.Path(__file__).resolve().parent.parent
result = subprocess.run(['php', str(root/'tools/performance-scalability-regression.php'), '--sql-json'],
                        check=True, capture_output=True, text=True)
data = json.loads(result.stdout)
con = sqlite3.connect(':memory:')
con.executescript('''
CREATE TABLE ce_kb_documents (id TEXT PRIMARY KEY, title TEXT, locale TEXT, visibility TEXT,
  revision INTEGER, indexed_revision INTEGER, source_kind TEXT, source_type TEXT, source_id TEXT,
  source_revision INTEGER, status TEXT, updated_at INTEGER);
CREATE TABLE ce_kb_terms (term TEXT, document_id TEXT, chunk_no INTEGER, frequency INTEGER,
 PRIMARY KEY(term,document_id,chunk_no));
CREATE TABLE ce_kb_chunks (document_id TEXT, chunk_no INTEGER, content TEXT,
 PRIMARY KEY(document_id,chunk_no));
''')
# The common term fills the entire per-term window. The rare one must survive.
for i in range(420):
    doc = f'doc-{i}'
    visibility = 'public' if i % 3 else 'internal'
    status = 'draft' if i % 17 == 0 else 'published'
    indexed = 0 if i % 19 == 0 else 1
    con.execute('INSERT INTO ce_kb_documents VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                (doc, 'Public document', 'ru', visibility, 1, indexed, 'manual', None, None, None, status, i))
    con.execute('INSERT INTO ce_kb_terms VALUES (?,?,?,?)', ('alpha', doc, 0, 1))
    con.execute('INSERT INTO ce_kb_chunks VALUES (?,?,?)', (doc, 0, 'Body'))
rare='rare-1'
con.execute('INSERT INTO ce_kb_documents VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            (rare,'Rare public','ru','public',1,1,'manual',None,None,None,'published',0))
con.execute('INSERT INTO ce_kb_terms VALUES (?,?,?,?)',('beta',rare,0,2))
con.execute('INSERT INTO ce_kb_chunks VALUES (?,?,?)',(rare,0,'Rare body'))
rows = con.execute(data['sql'], data['params']).fetchall()
assert len(rows) <= 351, len(rows)
assert any(row[0] == 'beta' and row[1] == rare for row in rows)
assert not any(row[1] == 'doc-0' for row in rows)  # draft/internal
assert all(row[1] == rare or (int(row[1].split('-')[1]) % 3 != 0
                                      and int(row[1].split('-')[1]) % 17 != 0
                                      and int(row[1].split('-')[1]) % 19 != 0) for row in rows)
assert len([row for row in rows if row[0] == 'alpha']) <= 350
print(json.dumps({'ok': True, 'database': 'sqlite', 'source': 'live PHP SQL',
                  'rows': len(rows), 'rare_term_preserved': True, 'access_filters': True}))
