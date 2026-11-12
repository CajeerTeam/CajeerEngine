#!/usr/bin/env python3
"""Exercise the real publish script against a deterministic fake GitHub CLI."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parent.parent
VERSION = (ROOT / 'VERSION').read_text().strip()
SCRIPT = ROOT / 'tools/github-publish-release.sh'
spec=importlib.util.spec_from_file_location('cert_reg',ROOT/'tools/certification-regression.py')
cert_reg=importlib.util.module_from_spec(spec);spec.loader.exec_module(cert_reg)
MOCK = '''#!/usr/bin/env python3
import hashlib, json, os, pathlib, sys
args = sys.argv[1:]
state = pathlib.Path(os.environ['MOCK_STATE'])
mode = os.environ['MOCK_MODE']
if args[:2] == ['release','view']:
    if '--json' not in args:
        sys.exit(0 if mode == 'existing' else 1)
    print(json.dumps({'isDraft': True, 'databaseId': 123}))
elif args[:2] == ['release','create']:
    assert '--draft' in args and '--verify-tag' in args
    if mode == 'upload-failed': sys.exit(1)
    files = [pathlib.Path(a) for a in args if a.startswith('/')]
    assets = [{'name':p.name, 'size':p.stat().st_size, 'state':'uploaded', 'digest':'sha256:'+hashlib.sha256(p.read_bytes()).hexdigest()} for p in files]
    if mode == 'incomplete': assets.pop()
    if mode == 'bad-digest': assets[0]['digest'] = 'sha256:'+'0'*64
    state.write_text(json.dumps({'assets':assets}))
elif args[:1] == ['api']:
    print(state.read_text())
elif args[:2] == ['release','edit']:
    assert '--draft=false' in args
    state.with_suffix('.published').touch()
else: sys.exit(2)
'''
with tempfile.TemporaryDirectory(prefix='ce-gh-publish-') as temp:
    base = Path(temp)
    gh = base / 'gh'
    gh.write_text(MOCK)
    gh.chmod(0o700)
    release = base / 'release'
    release.mkdir()
    # The publication mock uses synthetic reports; actual evidence is produced by the host smoke in CI.
    commit=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
    cert_reg.fixture(release,VERSION,commit)
    for mode in ('source', 'dist'):
        name = f'cajeerengine-{VERSION}-{mode}.zip'
        package = release / name
        (release / (name + '.sha256')).write_text(f'{hashlib.sha256(package.read_bytes()).hexdigest()}  {name}\n')
        (release / (name + '.sig')).write_text('fixture signature')
        (release / f'cajeerengine-{VERSION}-{mode}.manifest.json').write_text('{}')
    (release / f'cajeerengine-{VERSION}-artifacts.json').write_text('{}')
    def run(mode, tag='v'+VERSION, repo='cajeerteam/CajeerEngine', success=False):
        state = base / (mode+'.json')
        env = dict(os.environ, PATH=f'{base}:{os.environ["PATH"]}', MOCK_MODE=mode, MOCK_STATE=str(state))
        result = subprocess.run(['bash',str(SCRIPT),str(release),tag,repo],env=env,capture_output=True,text=True)
        assert (result.returncode == 0) == success, (mode,result.stdout,result.stderr)
        assert state.with_suffix('.published').exists() == success, mode
        print('PASS', mode)
    run('success',success=True)
    run('existing')
    run('upload-failed')
    run('incomplete')
    run('bad-digest')
    run('wrong-tag',tag='v0.0.0')
    run('wrong-repo',repo='other/repository')
    evidence=release/'certification.json'; original=evidence.read_bytes(); evidence.unlink()
    run('missing-certification'); evidence.write_bytes(original)
    report=release/'production-acceptance.json'; original=report.read_bytes(); report.write_text('{}')
    run('invalid-certification'); report.write_bytes(original)
    (release / f'cajeerengine-{VERSION}-dist.zip.sig').unlink()
    run('missing-signature')
