#!/usr/bin/env python3
"""Run the actual schema upgrade, rollback and resumable receipt CAS in SQLite."""
from pathlib import Path
import sqlite3
import tempfile

root = Path(__file__).resolve().parents[1]
files = sorted(p for p in (root / 'migrations/sqlite').glob('*.sql') if p.name[:4] <= '0021')
assert files[-1].name == '0021_migration_media.sql'
with tempfile.TemporaryDirectory(prefix='cajeer-media-migration-') as directory:
    database = Path(directory) / 'test.sqlite'
    connection = sqlite3.connect(database)
    connection.execute('PRAGMA foreign_keys = ON')
    for path in files[:-1]:
        connection.executescript(path.read_text())
    connection.execute('INSERT INTO ce_wordpress_migration_runs(project_id,plan_sha256,completed_at,result) VALUES(?,?,?,?)', ('a' * 32, 'b' * 64, '2026-11-17', '{}'))
    connection.commit()
    connection.executescript(files[-1].read_text())
    assert connection.execute('SELECT COUNT(*) FROM ce_wordpress_migration_runs').fetchone() == (1,)
    print(f'PASS {len(files)-1} prior SQLite migrations plus 0021; original content import receipts preserved')
    try:
        with connection:
            connection.execute('INSERT INTO ce_migration_media_files VALUES(?,?,?,?,?,?,?)', ('a' * 32, '2020/01/image.png', 'c' * 64, 'd' * 36, 123, 'public', '2026-11-17'))
            connection.execute('INSERT INTO ce_migration_media_rewrites VALUES(?,?,?,?,?,?,?,?)', ('a' * 32, 'e' * 36, 1, 'f' * 64, '1' * 64, 2, 'pending', '2026-11-17'))
            raise RuntimeError('abort after pending transaction')
    except RuntimeError:
        pass
    assert connection.execute('SELECT COUNT(*) FROM ce_migration_media_files').fetchone() == (0,)
    assert connection.execute('SELECT COUNT(*) FROM ce_migration_media_rewrites').fetchone() == (0,)
    print('PASS media file and rewrite receipts rollback together')
    with connection:
        connection.execute('INSERT INTO ce_migration_media_files VALUES(?,?,?,?,?,?,?)', ('a' * 32, '2020/01/image.png', 'c' * 64, 'd' * 36, 123, 'public', '2026-11-17'))
        connection.execute('INSERT INTO ce_migration_media_rewrites VALUES(?,?,?,?,?,?,?,?)', ('a' * 32, 'e' * 36, 1, 'f' * 64, '1' * 64, 2, 'pending', '2026-11-17'))
    try:
        with connection:
            connection.execute('INSERT INTO ce_migration_media_files VALUES(?,?,?,?,?,?,?)', ('a' * 32, '2020/01/image.png', 'c' * 64, 'z' * 36, 123, 'public', '2026-11-17'))
        raise AssertionError('duplicate source media ledger row must fail')
    except sqlite3.IntegrityError:
        print('PASS per-project source path uniqueness prevents duplicate imports')
    with connection:
        assert connection.execute("UPDATE ce_migration_media_rewrites SET status='done' WHERE project_id=? AND target_id=? AND status='pending'", ('a' * 32, 'e' * 36)).rowcount == 1
        assert connection.execute("UPDATE ce_migration_media_rewrites SET status='done' WHERE project_id=? AND target_id=? AND status='pending'", ('a' * 32, 'e' * 36)).rowcount == 0
    print('PASS pending/done receipt transition is one-time')
    backup = Path(directory) / 'backup.sqlite'
    with sqlite3.connect(backup) as target:
        connection.backup(target)
    with sqlite3.connect(backup) as target:
        assert target.execute('PRAGMA integrity_check').fetchone() == ('ok',)
        assert target.execute('SELECT COUNT(*) FROM ce_migration_media_files').fetchone() == (1,)
        assert target.execute('SELECT status FROM ce_migration_media_rewrites').fetchone() == ('done',)
    print('PASS SQLite integrity and backup of migrated media receipts')
