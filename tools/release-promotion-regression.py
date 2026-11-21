#!/usr/bin/env python3
"""Offline regression of certified tag promotion with a deterministic GitHub CLI.

No network access, GitHub tags, releases or secrets are mutated by this test.
"""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('ce_cert_fixture', ROOT/'tools/certification-regression.py')
fixture_module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture_module)
SCRIPT=ROOT/'tools/promote-certified-release.sh'
VERSION=(ROOT/'VERSION').read_text().strip()
COMMIT=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
assert len(COMMIT)==40

MOCK='''#!/usr/bin/env python3
import json, os, pathlib, sys
args=sys.argv[1:]
mode=os.environ['MOCK_MODE']
log=pathlib.Path(os.environ['MOCK_LOG'])
with log.open('a') as stream:stream.write(json.dumps(args)+'\\n')
if args[0]=='api':
    if 'git/ref/tags/' in args[1]:
        if mode in ('same-tag','different-tag'):
            print(json.dumps({'object':{'type':'tag','sha':'a'*40}}));sys.exit(0)
        sys.exit(1)
    if '/git/tags/' in args[1]:
        print(os.environ['MOCK_COMMIT'] if mode=='same-tag' else 'b'*40)
    elif '--method' in args:
        if '/git/tags' in args:
            print('a'*40)
        else:print('{}')
    else:sys.exit(2)
elif args[:2]==['release','view']:
    sys.exit(0 if mode=='published' else 1)
elif args[:2]==['workflow','run']:
    assert args[2]=='release-publish.yml'
    assert '--ref' in args and args[args.index('--ref')+1]=='v'+os.environ['MOCK_VERSION']
    assert '-f' in args and args[args.index('-f')+1]=='certified_run_id=454545'
else:sys.exit(2)
'''
with tempfile.TemporaryDirectory(prefix='ce-promotion-') as temp:
    base=Path(temp)
    fake=base/'gh'
    fake.write_text(MOCK)
    fake.chmod(0o755)
    artifacts=base/'certified'
    artifacts.mkdir()
    fixture_module.fixture(artifacts, VERSION, COMMIT)

    def run(mode, ref='refs/heads/main', expected=True, sha=COMMIT, alter_cert=False):
        log=base/(mode+ref.replace('/','_')+'.log')
        if log.exists():log.unlink()
        env={**os.environ, 'PATH':f'{base}:{os.environ["PATH"]}',
             'MOCK_MODE':mode,'MOCK_VERSION':VERSION,'MOCK_LOG':str(log),'MOCK_COMMIT':COMMIT,
             'GITHUB_REPOSITORY':'CajeerTeam/CajeerEngine','GITHUB_SHA':sha,
             'GITHUB_RUN_ID':'454545','GITHUB_REF':ref,'GITHUB_EVENT_NAME':'push'}
        result=subprocess.run(['bash',str(SCRIPT),str(artifacts)],env=env,text=True,capture_output=True)
        assert (result.returncode==0)==expected,(mode,ref,result.stdout,result.stderr)
        actions=[json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []
        dispatches=[action for action in actions if action[:2]==['workflow','run']]
        assert len(dispatches)==int(expected),(mode,ref,actions)
        if mode in ('different-tag','published') or not expected:
            assert not dispatches
        print('PASS',mode,ref)

    run('create-tag')
    run('same-tag')
    run('different-tag',expected=False)
    run('published',expected=False)
    run('tag-event',ref='refs/tags/v'+VERSION)
    run('wrong-ref',ref='refs/heads/feature',expected=False)
    run('wrong-commit',expected=False,sha='f'*40)

# Validate workflow ownership, trust boundary and explicit dispatch fields.
publish=(ROOT/'.github/workflows/release-publish.yml').read_text()
release=(ROOT/'.github/workflows/release.yml').read_text()
assert 'environment: release' not in release
assert 'environment: release' in publish
assert "if: startsWith(github.ref, 'refs/tags/v')" in publish
assert 'github-token: ${{ github.token }}' in publish
assert 'github.ref' in publish or 'GITHUB_REF' in publish
assert 'certification-evidence.py verify' in publish
assert 'gh api "$run" --jq' in publish
assert 'needs: release' in release
print('PASS protected tag-ref workflow isolation')
