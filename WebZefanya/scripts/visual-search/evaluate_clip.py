"""Evaluate the real local worker with checked-in representative fixtures; no business DB or image logging."""
import argparse
import ctypes
from ctypes import wintypes
import json
from pathlib import Path
import statistics
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
FIXTURES = ROOT / 'tests/Fixtures'
SUPPORTED = {'books', 'clothing', 'footwear', 'bags', 'electronics'}
MAPPING = {'book': 'books', 'stack-books': 'books', 'dress': 'clothing', 'shoes': 'footwear', 'bag': 'bags', 'laptop': 'electronics', 'phone': 'electronics', 'landscape': None}

def worker_cpu_seconds():
    """Read only the owned worker's cumulative kernel/user time; return None off Windows."""
    try:
        pid = int((ROOT / 'storage/app/private/visual-search/clip-runtime/worker.pid').read_text())
        kernel = ctypes.WinDLL('kernel32', use_last_error=True)
        kernel.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
        kernel.OpenProcess.restype = wintypes.HANDLE
        kernel.GetProcessTimes.argtypes = [wintypes.HANDLE] + [ctypes.POINTER(wintypes.FILETIME)] * 4
        kernel.CloseHandle.argtypes = [wintypes.HANDLE]
        handle = kernel.OpenProcess(0x1000, False, pid)
        if not handle:
            return None
        try:
            times = [wintypes.FILETIME() for _ in range(4)]
            if not kernel.GetProcessTimes(handle, *[ctypes.byref(value) for value in times]):
                return None
            return sum((value.dwHighDateTime << 32) + value.dwLowDateTime for value in times[2:]) / 10_000_000
        finally:
            kernel.CloseHandle(handle)
    except (OSError, ValueError, AttributeError):
        return None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    key = (ROOT / 'storage/app/private/visual-search/clip-runtime.key').read_text().strip()
    cases = []
    for row in json.loads((FIXTURES / 'visual-search/manifest.json').read_text()):
        expected = {1: 'books', 7: 'clothing', 8: 'electronics'}[row['category']]
        cases.append((FIXTURES / 'visual-search' / row['file'], expected, 'catalog_calibration'))
    for source in json.loads((FIXTURES / 'object-aware/sources.json').read_text()):
        cases.append((FIXTURES / 'object-aware' / source['file'], MAPPING[source['label']], 'independent_calibration'))
    for name in ['white', 'black', 'gray', 'noise', 'abstract']:
        cases.append((FIXTURES / 'object-aware' / (name + '.png'), None, 'negative'))
    for name, expected in [('stacked-books', 'books'), ('shoe', 'footwear'), ('bag', 'bags')]:
        cases.append((FIXTURES / 'visual-search-discovery' / (name + '-illustration.png'), expected, 'illustration'))
    for source in json.loads((FIXTURES / 'object-aware/holdout/sources.json').read_text()):
        cases.append((FIXTURES / 'object-aware/holdout' / source['file'], MAPPING[source['label']], 'held_out'))
    start_cpu = worker_cpu_seconds()
    started = time.perf_counter()
    results = []
    for path, expected, split in cases:
        request = urllib.request.Request('http://127.0.0.1:8766/embed', data=path.read_bytes(), headers={'X-Zefanya-Visual-Key': key, 'Content-Type': 'application/octet-stream'})
        begin = time.perf_counter()
        with urllib.request.urlopen(request, timeout=3) as response:
            data = json.load(response)
        accepted = data['family'] in SUPPORTED and data['score'] >= 0.24 and data['margin'] >= 0.015
        results.append({'file': path.relative_to(FIXTURES).as_posix(), 'split': split, 'expected': expected, 'prediction': data['family'] if accepted else None, 'score': data['score'], 'margin': data['margin'], 'milliseconds': round((time.perf_counter() - begin) * 1000, 3)})
    elapsed = time.perf_counter() - started
    end_cpu = worker_cpu_seconds()
    accepted = [r for r in results if r['prediction'] is not None]
    correct = [r for r in accepted if r['prediction'] == r['expected']]
    positive = [r for r in results if r['expected'] is not None]
    negative = [r for r in results if r['expected'] is None]
    latencies = sorted(r['milliseconds'] for r in results)
    with urllib.request.urlopen('http://127.0.0.1:8766/health', timeout=2) as response:
        health = json.load(response)
    output = {'model': health['version'], 'queries': len(results), 'accepted': len(accepted), 'correct_accepted': len(correct), 'precision_when_accepted': len(correct) / len(accepted) if accepted else None, 'positive_coverage': len(correct) / len(positive), 'negative_false_positives': sum(r['prediction'] is not None for r in negative), 'negative_queries': len(negative), 'mean_ms': statistics.mean(latencies), 'p95_ms': latencies[int(len(latencies) * .95 + .999999) - 1], 'worker_memory_mib': health['memory'], 'cpu_seconds': end_cpu - start_cpu if end_cpu is not None and start_cpu is not None else None, 'wall_seconds': elapsed, 'results': results, 'limits': 'Small fixture set, five supported families, not a general-market accuracy estimate; semantic scores are cosine heuristics, not confidence probabilities.'}
    if args.output:
        args.output.write_text(json.dumps(output, indent=2), encoding='utf-8')
    print(json.dumps({k: v for k, v in output.items() if k != 'results'}, indent=2))

if __name__ == '__main__':
    main()
