#!/usr/bin/env python3
"""Exercise the real publish script against a deterministic fake GitHub CLI."""
import hashlib
import importlib.util
import json
import os
import shutil
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
    expected_prerelease = '-' in os.environ['CE_TEST_VERSION']
    reported_prerelease = not expected_prerelease if mode == 'wrong-prerelease' else expected_prerelease
    print(json.dumps({'isDraft': True, 'isPrerelease': reported_prerelease, 'databaseId': 123}))
elif args[:2] == ['release','create']:
    assert '--draft' in args and '--verify-tag' in args
    assert ('--prerelease' in args) == ('-' in os.environ['CE_TEST_VERSION'])
    if mode == 'upload-failed': sys.exit(1)
    files = [pathlib.Path(a) for a in args if a.startswith('/')]
    assets = [{'name':p.name, 'size':p.stat().st_size, 'state':'uploaded', 'digest':'sha256:'+hashlib.sha256(p.read_bytes()).hexdigest()} for p in files]
    if mode == 'incomplete': assets.pop()
    if mode == 'bad-digest': assets[0]['digest'] = 'sha256:'+'0'*64
    if mode == 'missing-digest': assets[0].pop('digest',None)
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
    commit=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
    def fixture(version, label, checkout_commit=None):
        directory = base / label
        directory.mkdir()
        # The publication mock uses synthetic reports; actual evidence is produced by the host smoke in CI.
        cert_reg.fixture(directory,version,checkout_commit or commit)
        for mode in ('source', 'dist'):
            name = f'cajeerengine-{version}-{mode}.zip'
            package = directory / name
            (directory / (name + '.sha256')).write_text(f'{hashlib.sha256(package.read_bytes()).hexdigest()}  {name}\n')
            (directory / (name + '.sig')).write_text('fixture signature')
        return directory
    release = fixture(VERSION, 'release')
    def run(mode, tag='v'+VERSION, repo='cajeerteam/CajeerEngine', success=False,
            version=VERSION, directory=None, script=SCRIPT):
        state = base / (mode+'-'+version+'.json')
        env = dict(os.environ, PATH=f'{base}:{os.environ["PATH"]}', MOCK_MODE=mode, MOCK_STATE=str(state), CE_TEST_VERSION=version)
        result = subprocess.run(['bash',str(script),str(directory or release),tag,repo],env=env,capture_output=True,text=True)
        assert (result.returncode == 0) == success, (mode,version,result.stdout,result.stderr)
        assert state.with_suffix('.published').exists() == success, (mode,version)
        print('PASS', mode, version)
    run('success',success=True)
    run('existing')
    run('upload-failed')
    run('incomplete')
    run('bad-digest')
    run('missing-digest')
    run('wrong-prerelease')
    # A stable tag incorrectly marked prerelease and a prerelease tag incorrectly
    # marked stable must both be rejected. Use the real publisher for each channel.
    for prerelease_version in ('2.0.0-rc.1', '2.0.0-beta.1'):
        isolated_root = base / ('checkout-' + prerelease_version)
        tools = isolated_root / 'tools'
        tools.mkdir(parents=True)
        (isolated_root / 'VERSION').write_text(prerelease_version + '\n')
        subprocess.run(['git', '-C', str(isolated_root), 'init', '-q'], check=True)
        subprocess.run(['git', '-C', str(isolated_root), '-c', 'user.name=CI Fixture',
                        '-c', 'user.email=ci@local.test', 'commit', '-q', '--allow-empty',
                        '-m', 'prerelease fixture'], check=True)
        prerelease_commit = subprocess.check_output(
            ['git', '-C', str(isolated_root), 'rev-parse', 'HEAD'], text=True).strip()
        for file in ('github-publish-release.sh', 'certification-evidence.py', 'production-acceptance.py'):
            shutil.copyfile(ROOT / 'tools' / file, tools / file)
        prerelease_dir = fixture(prerelease_version, 'release-' + prerelease_version, prerelease_commit)
        run('success', tag='v' + prerelease_version, success=True, version=prerelease_version,
            directory=prerelease_dir, script=tools / 'github-publish-release.sh')
        run('wrong-prerelease', tag='v' + prerelease_version, version=prerelease_version,
            directory=prerelease_dir, script=tools / 'github-publish-release.sh')
    run('wrong-tag',tag='v0.0.0')
    run('wrong-repo',repo='other/repository')
    evidence=release/'certification.json'; original=evidence.read_bytes(); evidence.unlink()
    run('missing-certification'); evidence.write_bytes(original)
    report=release/'production-acceptance.json'; original=report.read_bytes(); report.write_text('{}')
    run('invalid-certification'); report.write_bytes(original)
    (release / f'cajeerengine-{VERSION}-dist.zip.sig').unlink()
    run('missing-signature')
