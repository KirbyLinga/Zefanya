"""Read-only runtime inventory. Never downloads/loads a model or starts a server."""
import ctypes
import importlib.metadata
import importlib.util
import json
import platform
import shutil
import re
import urllib.request
from pathlib import Path

class MemoryStatus(ctypes.Structure):
    _fields_ = [("length", ctypes.c_ulong), ("load", ctypes.c_ulong)] + [
        (name, ctypes.c_ulonglong) for name in ("total_physical", "free_physical", "total_pagefile", "free_pagefile", "total_virtual", "free_virtual", "free_extended")
    ]

result = {"python": platform.python_version(), "packages": {}, "model_loaded": False, "semantic_active": False}
for name in ["onnxruntime", "numpy", "Pillow", "fastapi", "uvicorn", "torch", "transformers"]:
    try:
        result["packages"][name] = importlib.metadata.version(name)
    except importlib.metadata.PackageNotFoundError:
        result["packages"][name] = None
if platform.system() == "Windows":
    memory = MemoryStatus()
    memory.length = ctypes.sizeof(memory)
    if ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(memory)):
        result["memory_mb"] = {"physical": round(memory.total_physical / 1048576, 1), "free": round(memory.free_physical / 1048576, 1)}
result["free_disk_gb"] = round(shutil.disk_usage(Path(__file__).resolve().anchor).free / 1073741824, 2)
try:
    import onnxruntime
    result["onnx_import"] = "passed"
    result["execution_providers"] = onnxruntime.get_available_providers()
except Exception as error:
    result["onnx_import"] = type(error).__name__
root = Path(__file__).resolve().parents[2]
configuration = (root / 'config/visual-search.php').read_text(encoding='utf-8')
enabled = re.search(r"'semantic'\s*=>\s*\[.*?'enabled'\s*=>\s*(true|false)", configuration, re.S)
result['configured_in_source'] = enabled is not None and enabled.group(1) == 'true'
result['configuration_cached'] = (root / 'bootstrap/cache/config.php').exists()
try:
    with urllib.request.urlopen('http://127.0.0.1:8766/health', timeout=1.5) as response:
        result['worker'] = json.load(response)
    result['model_loaded'] = result['worker'].get('ready') is True
    result['semantic_active'] = result['model_loaded'] and result['configured_in_source'] and not result['configuration_cached']
except Exception as error:
    result['worker'] = {'ready': False, 'reason': type(error).__name__}
result['decision'] = 'Source configuration plus local worker health were checked. Cached configuration, if present, needs separate effective-configuration verification. Public recognition behavior still requires an actual upload test.'
print(json.dumps(result, indent=2))
