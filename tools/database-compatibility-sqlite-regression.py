#!/usr/bin/env python3
"""Live SQLite 3.40+ acceptance of every immutable published migration."""
from pathlib import Path
import sqlite3
import subprocess
import json
import tempfile

root = Path(__file__).resolve().parent.parent
paths = sorted((root / 'migrations' / 'sqlite').glob('*.sql'))
assert len(paths) >= 40, 'missing expected SQLite migrations'
# Drive SQLite through the *same PHP SQL splitter* used by MigrationRunner,
# even on development hosts lacking pdo_sqlite.
php_parser = r'''require 'core/Database/MigrationSqlExecutor.php';
foreach (glob('migrations/sqlite/*.sql') as $file) {
    $out[basename($file)] = \CajeerEngine\Database\MigrationSqlExecutor::statements(file_get_contents($file));
}
echo json_encode($out, JSON_THROW_ON_ERROR);'''
parsed = json.loads(subprocess.check_output(['php', '-r', php_parser], cwd=root, text=True))
with tempfile.TemporaryDirectory(prefix='ce-db-compat-') as temp:
    con = sqlite3.connect(str(Path(temp) / 'acceptance.sqlite'))
    con.execute('PRAGMA foreign_keys=ON')
    for migration in paths:
        try:
            con.execute('BEGIN')
            for statement in parsed[migration.name]:
                con.execute(statement)
            con.commit()
        except sqlite3.DatabaseError as err:
            con.rollback()
            raise AssertionError(f'SQLite migration {migration.name} failed: {err}') from err
    assert con.execute('PRAGMA foreign_key_check').fetchall() == [], 'foreign key corruption'
    assert con.execute('PRAGMA integrity_check').fetchone() == ('ok',), 'SQLite integrity check failed'
    expected = {'ce_users', 'ce_api_tokens', 'ce_jobs', 'ce_ai_automations', 'ce_ai_automation_runs'}
    tables = {row[0] for row in con.execute("SELECT name FROM sqlite_master WHERE type='table'")}
    assert expected <= tables, f'Missing required tables: {expected - tables}'
    indexes = {row[0] for row in con.execute("SELECT name FROM sqlite_master WHERE type='index'")}
    assert {'idx_ai_automation_runs_retention', 'idx_ai_automation_runs_pending_owner'} <= indexes
    try:
        con.execute("INSERT INTO ce_ai_automations (id,user_id,title,prompt,interval_minutes,next_run_at,created_at,updated_at) VALUES ('bad','nonexistent','test','prompt',60,1,1,1)")
    except sqlite3.IntegrityError:
        pass
    else:
        raise AssertionError('Orphaned automation was accepted (foreign keys disabled)')
    assert con.execute('PRAGMA foreign_key_check').fetchall() == []
    print(f'OK: SQLite {sqlite3.sqlite_version}; {len(paths)} published migrations, FK isolation, schema, indexes, integrity')
