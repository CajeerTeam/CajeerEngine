#!/usr/bin/env python3
"""SQL upgrade, atomic cross-table receipt and backup recovery for SEO & URL migration."""
import sqlite3
import tempfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
files = sorted(p for p in (root/'migrations/sqlite').glob('*.sql') if p.name[:4] <= '0022')
assert files[-1].name == '0022_seo_url_migration.sql', files[-1]
with tempfile.TemporaryDirectory(prefix='cajeer-seo-migrate-') as folder:
    source = Path(folder) / 'source.sqlite'
    db = sqlite3.connect(source)
    db.execute('PRAGMA foreign_keys = ON')
    for path in files[:-1]:
        db.executescript(path.read_text())
    db.execute('INSERT INTO ce_wordpress_migration_runs(project_id,plan_sha256,completed_at,result) VALUES(?,?,?,?)', ('a'*32,'b'*64,'2026-11-17','{}'))
    db.commit()
    db.executescript(files[-1].read_text())
    assert db.execute('SELECT COUNT(*) FROM ce_wordpress_migration_runs').fetchone() == (1,)
    print(f'PASS {len(files)-1} prior migration scripts and 0022 applied; earlier receipts preserved')
    try:
        with db:
            db.execute('INSERT INTO ce_migration_seo_meta(project_id,target_id,title,description,canonical_url,noindex,created_at) VALUES(?,?,?,?,?,?,?)', ('a'*32,'1'*36,'SEO title','Description','https://site.test/new',1,'2026-11-17'))
            db.execute('INSERT INTO ce_migration_seo_query_urls(query_key,project_id,target_path,created_at) VALUES(?,?,?,?)', ('p:42','a'*32,'/new','2026-11-17'))
            db.execute('INSERT INTO ce_migration_seo_runs VALUES(?,?,?,?)', ('a'*32,'b'*64,'2026-11-17','{}'))
            raise RuntimeError('simulate failing redirect after metadata update')
    except RuntimeError:
        pass
    for table in ['ce_migration_seo_meta','ce_migration_seo_query_urls','ce_migration_seo_runs']:
        assert db.execute(f'SELECT COUNT(*) FROM {table}').fetchone() == (0,), table
    print('PASS metadata, query aliases and execution receipt rollback atomically')
    with db:
        db.execute('INSERT INTO ce_migration_seo_meta(project_id,target_id,title,description,canonical_url,noindex,created_at) VALUES(?,?,?,?,?,?,?)', ('a'*32,'1'*36,'SEO title','Description','https://site.test/new',1,'2026-11-17'))
        db.execute('INSERT INTO ce_migration_seo_query_urls(query_key,project_id,target_path,created_at) VALUES(?,?,?,?)', ('p:42','a'*32,'/new','2026-11-17'))
        db.execute('INSERT INTO ce_migration_seo_runs VALUES(?,?,?,?)', ('a'*32,'b'*64,'2026-11-17','{}'))
    for sql, args in [('INSERT INTO ce_migration_seo_meta(project_id,target_id,title,description,canonical_url,noindex,created_at) VALUES(?,?,?,?,?,?,?)',('z'*32,'1'*36,'X','Y','',0,'2026-11-17')),('INSERT INTO ce_migration_seo_query_urls(query_key,project_id,target_path,created_at) VALUES(?,?,?,?)',('p:42','z'*32,'/evil','2026-11-17')),('INSERT INTO ce_migration_seo_runs VALUES(?,?,?,?)',('a'*32,'x'*64,'2026-11-17','{}'))]:
        try:
            with db: db.execute(sql,args)
            raise AssertionError('Unique constraint not enforced: '+sql)
        except sqlite3.IntegrityError:
            pass
    print('PASS one SEO result per project, target and plain URL prevents duplicates')
    assert db.execute('SELECT target_id FROM ce_migration_seo_meta WHERE noindex=1').fetchone() == ('1'*36,)
    assert db.execute('PRAGMA integrity_check').fetchone() == ('ok',)
    copy=Path(folder)/'restored.sqlite'
    with sqlite3.connect(copy) as target:db.backup(target)
    with sqlite3.connect(copy) as restored:
        assert restored.execute('SELECT target_path FROM ce_migration_seo_query_urls WHERE query_key=?',('p:42',)).fetchone() == ('/new',)
        assert restored.execute('PRAGMA integrity_check').fetchone() == ('ok',)
    print('PASS indexed noindex query and verified SQLite backup restore')
