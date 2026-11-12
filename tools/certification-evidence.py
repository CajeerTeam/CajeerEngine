#!/usr/bin/env python3
"""Bind successful CI acceptance to exact source/dist bytes and checkout commit."""
import argparse
import importlib.util
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('acceptance', ROOT/'tools/production-acceptance.py')
a = importlib.util.module_from_spec(spec); spec.loader.exec_module(a)


def read(path):
    def reject_constant(value): raise ValueError('Invalid JSON number')
    with path.open() as f: return json.load(f, parse_constant=reject_constant)


def check(condition, message):
    if not condition: raise ValueError(message)


def inputs(directory, version):
    names = [f'cajeerengine-{version}-{mode}.zip' for mode in ('source','dist')]
    names += ['production-acceptance.json','dependency-audit.json']
    return {name:a.sha(directory/name) for name in names}


def validate_reports(directory, version, hashes):
    host = read(directory/'production-acceptance.json')
    check(host.get('schema')==1 and host.get('ok') is True and host.get('version')==version
          and host.get('profile')=='nginx-tls-fpm-pgsql' and host.get('artifact_sha256')==hashes[f'cajeerengine-{version}-dist.zip'], 'Host acceptance missing or mismatched')
    check(host.get('global_rate_limit')==10000 and host.get('auth_rate_limit')==20, 'Unexpected load profile')
    check(host.get('checks')==dict.fromkeys(a.SECURITY,True) and a.load_pass(host['load']), 'Security/load acceptance failed')
    audit = read(directory/'dependency-audit.json')
    # Composer JSON contains advisories and abandoned packages. Fail closed on errors or unknown shape.
    check('advisories' in audit and audit['advisories'] in ({},[]) and 'abandoned' in audit
          and audit['abandoned'] in ({},[]) and not audit.get('ignored-advisories') and not audit.get('ignored-severity') and not audit.get('error'), 'Dependency audit not clean')


def seal(directory, version, commit):
    hashes = inputs(directory,version); validate_reports(directory,version,hashes)
    report = {'schema':1,'version':version,'commit':commit,'ok':True,'sha256':hashes,
              'scope':'release CI integration gates + disposable production host + dependency audit'}
    a.write_report(directory/'certification.json',report)


def verify(directory, version, commit):
    hashes=inputs(directory,version); validate_reports(directory,version,hashes)
    report=read(directory/'certification.json')
    check(report.get('schema')==1 and report.get('ok') is True and report.get('version')==version
          and report.get('commit')==commit and report.get('sha256')==hashes,'Certification not bound to this checkout/artifacts')


def main():
    p=argparse.ArgumentParser(description=__doc__); p.add_argument('action',choices=('seal','verify')); p.add_argument('directory',type=Path)
    args=p.parse_args(); version=(ROOT/'VERSION').read_text().strip()
    commit=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
    check(re.fullmatch('[0-9a-f]{40}',commit) is not None,'Invalid checkout commit')
    (seal if args.action=='seal' else verify)(args.directory,version,commit)
    print('PASS certification',args.action,version)

if __name__=='__main__': main()
