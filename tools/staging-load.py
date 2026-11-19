#!/usr/bin/env python3
"""HTTPS-only, read-only staging HTTP load runner with measurable pass/fail gates.

Example:
  CE_LOAD_DISPOSABLE=yes python3 tools/staging-load.py --base-url https://staging.example \
    --route /api/v1/health --route / --workers 16 --duration 120 \
    --min-rps 20 --max-p95-ms 800 --max-errors 0 --report load.json

The target MUST be an explicitly disposable host. Never use on production.
"""
import argparse
import concurrent.futures
import http.client
import json
import math
import os
import ssl
import sys
import time
from collections import Counter
from pathlib import Path
from urllib.parse import urlsplit


def percentile(samples, fraction):
    if not samples:
        return None
    ordered = sorted(samples)
    return round(ordered[max(0, math.ceil(len(ordered) * fraction) - 1)], 3)


def parse_args():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', required=True, help='HTTPS target (disposable staging only)')
    parser.add_argument('--route', action='append', required=True, help='GET path starting with /; repeat for mixed workload')
    parser.add_argument('--workers', type=int, default=8)
    parser.add_argument('--duration', type=int, default=30, help='measurement window, seconds')
    parser.add_argument('--warmup', type=int, default=2, help='warmup window, seconds')
    parser.add_argument('--min-rps', type=float, default=10)
    parser.add_argument('--max-p95-ms', type=float, default=1000)
    parser.add_argument('--max-errors', type=int, default=0)
    parser.add_argument('--ca', type=Path, help='custom CA for verified staging TLS')
    parser.add_argument('--auth-env', help='name of env variable holding bearer token; never printed')
    parser.add_argument('--report', type=Path, required=True)
    a = parser.parse_args()
    if os.environ.get('CE_LOAD_DISPOSABLE') != 'yes':
        parser.error('CE_LOAD_DISPOSABLE=yes required; never load-test production')
    url = urlsplit(a.base_url)
    if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment or url.path not in ('', '/'):
        parser.error('base URL must be a bare HTTPS origin without embedded credentials')
    for route in a.route:
        parsed = urlsplit(route)
        if not route.startswith('/') or route.startswith('//') or parsed.scheme or parsed.netloc or parsed.fragment or '\\' in route or any(x in ('..', '.') for x in parsed.path.split('/')):
            parser.error('routes must be safe relative HTTP paths')
    if not (1 <= a.workers <= 128 and 3 <= a.duration <= 3600 and 0 <= a.warmup <= 120):
        parser.error('workers/duration/warmup out of bounds')
    if not (math.isfinite(a.min_rps) and 0 <= a.min_rps <= 1e6 and math.isfinite(a.max_p95_ms) and 1 <= a.max_p95_ms <= 60000 and 0 <= a.max_errors <= 100000):
        parser.error('invalid performance thresholds')
    if a.report.exists():
        parser.error('report already exists; refuse to overwrite evidence')
    token = os.environ.get(a.auth_env, '') if a.auth_env else ''
    if a.auth_env and not token:
        parser.error('configured bearer token missing')
    return a, url, token


def measure(args, origin, token):
    context = ssl.create_default_context(cafile=str(args.ca) if args.ca else None)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    deadline = time.monotonic() + args.duration + args.warmup
    measurement_start = time.monotonic() + args.warmup

    def worker(worker_index):
        rows = []
        sequence = worker_index
        connection = None
        try:
            while time.monotonic() < deadline:
                route = args.route[sequence % len(args.route)]
                sequence += args.workers
                error = None
                started = time.monotonic()
                try:
                    if connection is None:
                        connection = http.client.HTTPSConnection(origin.hostname, origin.port or 443, timeout=8, context=context)
                    headers = {'Accept': 'application/json, text/html;q=0.9', 'User-Agent': 'CajeerEngine-Staging-Load/1.8.5'}
                    if token:
                        headers['Authorization'] = 'Bearer ' + token
                    connection.request('GET', route, headers=headers)
                    response = connection.getresponse()
                    # Bounded read; do not keep body or secrets in the report.
                    length = 0
                    while True:
                        chunk = response.read(65536)
                        if not chunk:
                            break
                        length += len(chunk)
                        if length > 4 * 1024 * 1024:
                            error = 'oversized_response'
                            connection.close()
                            connection = None
                            break
                    if response.status < 200 or response.status >= 300:
                        error = f'http_{response.status}'
                    if response.will_close and connection is not None:
                        connection.close()
                        connection = None
                except (OSError, ssl.SSLError, http.client.HTTPException, TimeoutError):
                    error = 'transport_failure'
                    if connection is not None:
                        connection.close()
                        connection = None
                elapsed_ms = (time.monotonic() - started) * 1000
                if started >= measurement_start:
                    rows.append((route, elapsed_ms, error))
        finally:
            if connection is not None:
                connection.close()
        return rows

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        blocks = list(pool.map(worker, range(args.workers)))
    rows = [item for block in blocks for item in block]
    duration = max(0.00001, time.monotonic() - measurement_start)
    errors = Counter(e for _, _, e in rows if e)
    route_metrics = {}
    for route in args.route:
        group = [(ms, e) for name, ms, e in rows if name == route]
        route_metrics[route] = {'requests': len(group), 'errors': sum(e is not None for _, e in group),
                                'p50_ms': percentile([ms for ms, _ in group], .50),
                                'p95_ms': percentile([ms for ms, _ in group], .95),
                                'p99_ms': percentile([ms for ms, _ in group], .99)}
    p95 = percentile([ms for _, ms, _ in rows], .95)
    rps = round(len(rows) / duration, 3)
    passed = len(rows) > 0 and rps >= args.min_rps and p95 is not None and p95 <= args.max_p95_ms and sum(errors.values()) <= args.max_errors
    return {'schema': 1, 'version': '1.8.5', 'ok': passed, 'workers': args.workers, 'duration_seconds': round(duration, 3),
            'warmup_seconds': args.warmup, 'requests': len(rows), 'rps': rps, 'p50_ms': percentile([ms for _, ms, _ in rows], .5),
            'p95_ms': p95, 'p99_ms': percentile([ms for _, ms, _ in rows], .99), 'errors': sum(errors.values()),
            'error_types': dict(sorted(errors.items())), 'routes': route_metrics,
            'thresholds': {'min_rps': args.min_rps, 'max_p95_ms': args.max_p95_ms, 'max_errors': args.max_errors}}


def main():
    args, origin, token = parse_args()
    report = measure(args, origin, token)
    args.report.parent.mkdir(parents=True, exist_ok=True)
    with args.report.open('x', encoding='utf-8') as f:
        json.dump(report, f, indent=2, sort_keys=True)
        f.write('\n')
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if report['ok'] else 1


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError, ValueError) as exc:
        # Do not print URLs or credentials from exceptions.
        print(f'Load runner configuration or I/O failed: {type(exc).__name__}', file=sys.stderr)
        sys.exit(2)
