#!/usr/bin/env python3
"""Certify the actual release payloads, sidecars and external acceptance evidence.

This is a release/publish gate, not a substitute for running integration tests. It
re-opens both ZIPs and binds their contents to the production-host report and commit.
"""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import stat
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('acceptance', ROOT/'tools/production-acceptance.py')
a = importlib.util.module_from_spec(spec); spec.loader.exec_module(a)
MAX_MEMBER = 256 * 1024 * 1024
MAX_UNCOMPRESSED = 2 * 1024 * 1024 * 1024
MAX_MANIFEST = 16 * 1024 * 1024
HEX = re.compile(r'[a-f0-9]{64}\Z')


def check(condition, message):
    if not condition: raise ValueError(message)


def read(path):
    def reject_constant(value): raise ValueError('Invalid JSON number')
    with path.open(encoding='utf-8') as f: return json.load(f, parse_constant=reject_constant)


def artifact_names(version):
    return {mode: f'cajeerengine-{version}-{mode}.zip' for mode in ('source', 'dist')}


def verify_zip(directory, version, mode):
    """Inspect real ZIP entries, rejecting duplicated, malformed and nonregular members."""
    name = artifact_names(version)[mode]
    path = directory/name
    digest = a.sha(path)
    with zipfile.ZipFile(path) as archive:
        infos = archive.infolist()
        names = [entry.filename for entry in infos]
        check(len(names) == len(set(names)), f'{name}: duplicate ZIP entries')
        check('cajeerengine/release-manifest.json' in names, f'{name}: missing embedded manifest')
        sizes = sum(entry.file_size for entry in infos)
        check(sizes <= MAX_UNCOMPRESSED, f'{name}: excessive extraction size')
        for entry in infos:
            path_parts = entry.filename.split('/')
            check(entry.file_size <= MAX_MEMBER or entry.filename == 'cajeerengine/release-manifest.json', f'{name}: oversized file')
            check(not entry.is_dir() and len(path_parts) >= 2 and path_parts[0] == 'cajeerengine'
                  and all(p and p not in ('.', '..') for p in path_parts)
                  and '\\' not in entry.filename and '\x00' not in entry.filename,
                  f'{name}: unsafe ZIP entry')
            unix_mode = (entry.external_attr >> 16) & 0o170000
            check(entry.create_system != 3 or unix_mode in (0, stat.S_IFREG), f'{name}: nonregular member')
            check(not (entry.flag_bits & 1), f'{name}: encrypted member')
        info = archive.getinfo('cajeerengine/release-manifest.json')
        check(info.file_size <= MAX_MANIFEST, f'{name}: oversized manifest')
        manifest = json.loads(archive.read(info))
        check(isinstance(manifest, dict) and manifest.get('format') == 'cajeerengine.release-manifest.v5'
              and manifest.get('project') == 'cajeerengine' and manifest.get('version') == version
              and manifest.get('mode') == mode and manifest.get('artifact') == name,
              f'{name}: manifest identity mismatch')
        files, checksums = manifest.get('files'), manifest.get('checksums')
        check(isinstance(files, list) and all(isinstance(f, str) for f in files)
              and files == sorted(set(files)) and isinstance(checksums, dict)
              and set(checksums) == set(files), f'{name}: bad inventory')
        check(set(names) == {'cajeerengine/release-manifest.json'} | {'cajeerengine/'+f for f in files},
              f'{name}: extra or missing members')
        check({'VERSION', 'release.json', 'api/openapi.yaml',
               'public/admin/assets/admin-manifest.json'} <= set(files),
              f'{name}: identity files are missing')
        for file in files:
            expected = checksums[file]
            check(isinstance(expected, str) and HEX.fullmatch(expected) is not None,
                  f'{name}: invalid digest for {file}')
            h = hashlib.sha256()
            with archive.open('cajeerengine/'+file) as f:
                for chunk in iter(lambda: f.read(1024*1024), b''):
                    h.update(chunk)
            check(h.hexdigest() == expected, f'{name}: content mismatch for {file}')
        check(archive.read('cajeerengine/VERSION').decode().strip() == version,
              f'{name}: embedded VERSION mismatch')
        release = json.loads(archive.read('cajeerengine/release.json'))
        expected_channel = ('rc' if '-rc' in version else 'beta' if '-beta' in version
                            else 'dev' if '-' in version else 'stable')
        check(release.get('version') == version and release.get('channel') == expected_channel,
              f'{name}: embedded channel/version mismatch')
        api = json.loads(archive.read('cajeerengine/api/openapi.yaml'))
        check(api.get('info', {}).get('version') == version,
              f'{name}: embedded API version mismatch')
        admin = json.loads(archive.read('cajeerengine/public/admin/assets/admin-manifest.json'))
        check(admin.get('version') == version and admin.get('ready') is True,
              f'{name}: stale published admin')
    checksum = (directory/(name+'.sha256')).read_text(encoding='ascii')
    check(checksum == f'{digest}  {name}\n', f'{name}: checksum sidecar mismatch')
    sidecar = read(directory/(name.removesuffix('.zip')+'.manifest.json'))
    check(sidecar == {**manifest, 'artifact_sha256':digest}, f'{name}: manifest sidecar mismatch')
    return digest, checksums


def verify_artifacts(directory, version):
    verified = {mode: verify_zip(directory, version, mode) for mode in ('source', 'dist')}
    hashes = {mode: result[0] for mode, result in verified.items()}
    source_files, dist_files = verified['source'][1], verified['dist'][1]
    # Dist may add locked vendor packages and omit development files. All shared
    # application payloads must be byte-for-byte identical to the qualified source.
    shared = source_files.keys() & dist_files.keys()
    check({'VERSION', 'release.json', 'api/openapi.yaml'} <= shared,
          'Source and dist have no common release identity')
    for file in sorted(shared):
        check(source_files[file] == dist_files[file],
              f'Source/dist application payload mismatch: {file}')
    index = read(directory/f'cajeerengine-{version}-artifacts.json')
    check(index.get('format') == 'cajeerengine.release-artifacts.v1' and index.get('version') == version,
          'Invalid artifact index')
    entries = index.get('artifacts')
    check(isinstance(entries, list) and len(entries) == 2, 'Artifact index must contain source and dist')
    by_mode = {entry.get('mode'):entry for entry in entries if isinstance(entry, dict)}
    check(set(by_mode) == set(hashes), 'Artifact index modes are inconsistent')
    for mode, digest in hashes.items():
        name = artifact_names(version)[mode]
        entry = by_mode[mode]
        check(entry.get('name') == name and entry.get('sha256') == digest
              and entry.get('size') == (directory/name).stat().st_size
              and Path(entry.get('path', '')).name == name
              and Path(entry.get('checksum_file', '')).name == name+'.sha256',
              f'Artifact index mismatches {mode}')
    return hashes


def inputs(directory, version):
    names = [f'cajeerengine-{version}-{mode}.zip' for mode in ('source','dist')]
    names += [f'cajeerengine-{version}-{mode}.zip.sha256' for mode in ('source','dist')]
    names += [f'cajeerengine-{version}-{mode}.manifest.json' for mode in ('source','dist')]
    names += [f'cajeerengine-{version}-artifacts.json', 'production-acceptance.json','dependency-audit.json']
    return {name:a.sha(directory/name) for name in names}


def validate_reports(directory, version, hashes):
    host = read(directory/'production-acceptance.json')
    check(host.get('schema')==1 and host.get('ok') is True and host.get('version')==version
          and host.get('profile')=='nginx-tls-fpm-pgsql' and host.get('artifact_sha256')==hashes[f'cajeerengine-{version}-dist.zip'],
          'Host acceptance missing or mismatched')
    check(host.get('global_rate_limit')==10000 and host.get('auth_rate_limit')==20, 'Unexpected load profile')
    check(host.get('checks')==dict.fromkeys(a.SECURITY,True) and a.load_pass(host['load']),
          'Security/load acceptance failed')
    audit = read(directory/'dependency-audit.json')
    check('advisories' in audit and audit['advisories'] in ({},[]) and 'abandoned' in audit
          and audit['abandoned'] in ({},[]) and not audit.get('ignored-advisories')
          and not audit.get('ignored-severity') and not audit.get('error'), 'Dependency audit not clean')


def seal(directory, version, commit):
    verify_artifacts(directory, version)
    hashes = inputs(directory,version); validate_reports(directory,version,hashes)
    report = {'schema':1,'version':version,'commit':commit,'ok':True,'sha256':hashes,
              'scope':'release CI integration gates + disposable production host + dependency audit + exact ZIP and sidecar inventory'}
    a.write_report(directory/'certification.json',report)


def verify(directory, version, commit):
    verify_artifacts(directory, version)
    hashes=inputs(directory,version); validate_reports(directory,version,hashes)
    report=read(directory/'certification.json')
    check(report.get('schema')==1 and report.get('ok') is True and report.get('version')==version
          and report.get('commit')==commit and report.get('sha256')==hashes,
          'Certification not bound to this checkout/artifacts')


def main():
    p=argparse.ArgumentParser(description=__doc__); p.add_argument('action',choices=('seal','verify')); p.add_argument('directory',type=Path)
    args=p.parse_args(); version=(ROOT/'VERSION').read_text().strip()
    commit=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
    check(re.fullmatch('[0-9a-f]{40}',commit) is not None,'Invalid checkout commit')
    (seal if args.action=='seal' else verify)(args.directory,version,commit)
    print('PASS certification',args.action,version)

if __name__=='__main__': main()
