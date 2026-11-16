"""Pinned CPU CLIP worker: loopback only, one inference at a time, 512 MiB committed-memory cap."""
import ctypes, json, time, sys, os
os.environ['OPENBLAS_NUM_THREADS'] = '1'
os.environ['OMP_NUM_THREADS'] = '1'
from ctypes import wintypes as w
from pathlib import Path

class Basic(ctypes.Structure):
    _fields_ = [('per_process', ctypes.c_longlong), ('per_job', ctypes.c_longlong), ('flags', w.DWORD), ('min_ws', ctypes.c_size_t), ('max_ws', ctypes.c_size_t), ('processes', w.DWORD), ('affinity', ctypes.c_size_t), ('priority', w.DWORD), ('scheduling', w.DWORD)]

class IO(ctypes.Structure):
    _fields_ = [(n, ctypes.c_ulonglong) for n in ['read_ops', 'write_ops', 'other_ops', 'read_bytes', 'write_bytes', 'other_bytes']]

class Extended(ctypes.Structure):
    _fields_ = [('basic', Basic), ('io', IO), ('process_memory', ctypes.c_size_t), ('job_memory', ctypes.c_size_t), ('peak_process', ctypes.c_size_t), ('peak_job', ctypes.c_size_t)]
k = ctypes.WinDLL('kernel32', use_last_error=True)
k.CreateJobObjectW.argtypes = [ctypes.c_void_p, w.LPCWSTR]
k.CreateJobObjectW.restype = w.HANDLE
k.GetCurrentProcess.restype = w.HANDLE
k.SetInformationJobObject.argtypes = [w.HANDLE, ctypes.c_int, ctypes.c_void_p, w.DWORD]
k.AssignProcessToJobObject.argtypes = [w.HANDLE, w.HANDLE]
k.SetPriorityClass.argtypes = [w.HANDLE, w.DWORD]
job = k.CreateJobObjectW(None, None)
limits = Extended()
limits.basic.flags = 256
limits.process_memory = 512 * 1024 * 1024
if not k.SetInformationJobObject(job, 9, ctypes.byref(limits), ctypes.sizeof(limits)) or not k.AssignProcessToJobObject(job, k.GetCurrentProcess()):
    raise ctypes.WinError(ctypes.get_last_error())
k.SetPriorityClass(k.GetCurrentProcess(), 16384)

class Memory(ctypes.Structure):
    _fields_ = [('cb', w.DWORD), ('faults', w.DWORD)] + [(n, ctypes.c_size_t) for n in ['peak_ws', 'ws', 'peak_paged', 'paged', 'peak_nonpaged', 'nonpaged', 'pagefile', 'peak_pagefile', 'private']]
k.K32GetProcessMemoryInfo.argtypes = [w.HANDLE, ctypes.c_void_p, w.DWORD]

def memory():
    m = Memory()
    m.cb = ctypes.sizeof(m)
    k.K32GetProcessMemoryInfo(k.GetCurrentProcess(), ctypes.byref(m), m.cb)
    return {n: round(getattr(m, n) / 1048576, 2) for n in ['peak_ws', 'ws', 'private', 'peak_pagefile']}
import numpy as np
import onnxruntime as ort
from PIL import Image, ImageOps
from tokenizers import Tokenizer
ROOT = Path(__file__).resolve().parents[2] / 'storage/app/private/visual-search/clip-runtime'
VERSION = 'clip-b32-int8-6b7c523-types-v1'
TYPES = {'books': ('Books', ['book', 'stack of books', 'open book', 'book cover']), 'clothing': ('Clothing', ['dress', 'shirt', 'blouse', 'jacket', 'trousers', 'skirt']), 'footwear': ('Footwear', ['shoe', 'pair of shoes', 'sneakers', 'boots', 'sandals']), 'bags': ('Bags', ['handbag', 'backpack', 'shoulder bag', 'purse']), 'electronics': ('Electronics', ['laptop computer', 'smartphone', 'headphones', 'camera', 'television', 'video game console']), 'furniture': ('Furniture', ['chair', 'desk', 'sofa', 'table', 'cabinet']), 'beauty': ('Beauty products', ['cosmetics', 'makeup', 'perfume bottle', 'skin care products']), 'plants': ('Plants', ['potted plant', 'flower', 'garden plant']), 'food': ('Food', ['food', 'cooked meal', 'fruit', 'bread']), 'jewelry': ('Jewelry and watches', ['necklace', 'ring', 'earrings', 'wristwatch']), 'toys': ('Toys', ['toy', 'teddy bear', 'building block toy']), 'vehicles': ('Vehicles', ['car', 'motorcycle', 'bicycle']), 'animals': ('Animals', ['dog', 'cat', 'bird']), 'other': ('Unrecognized', ['blank image', 'abstract painting', 'random noise', 'website screenshot', 'landscape', 'human face'])}

def options():
    o = ort.SessionOptions()
    o.intra_op_num_threads = 1
    o.inter_op_num_threads = 1
    o.enable_cpu_mem_arena = False
    o.enable_mem_pattern = False
    o.graph_optimization_level = ort.GraphOptimizationLevel.ORT_DISABLE_ALL
    o.add_session_config_entry('session.disable_prepacking', '1')
    return o

def unit(x):
    return x / np.maximum(np.linalg.norm(x, axis=-1, keepdims=True), 1e-12)

def prototypes():
    cache = ROOT / (VERSION + '-prototypes.json')
    if cache.exists():
        return json.loads(cache.read_text())
    session = ort.InferenceSession(str(ROOT / 'model/onnx/text_model_int8.onnx'), sess_options=options(), providers=['CPUExecutionProvider'])
    tokenizer = Tokenizer.from_file(str(ROOT / 'model/tokenizer.json'))
    tokenizer.enable_truncation(max_length=77)
    tokenizer.enable_padding(length=77, pad_id=49407, pad_token='<|endoftext|>')
    result = {}
    for family, (label, objects) in TYPES.items():
        vectors = []
        for item in objects:
            prompt_vectors = []
            for prefix in ['a photo of a ', 'an illustration of a ']:
                ids = np.array([tokenizer.encode(prefix + item).ids], dtype=np.int64)
                prompt_vectors.append(unit(session.run(None, {'input_ids': ids})[0])[0])
            vectors.append(unit(np.mean(prompt_vectors, axis=0)).tolist())
        result[family] = {'label': label, 'vectors': vectors}
    cache.write_text(json.dumps(result))
    return result

class Encoder:

    def __init__(self):
        self.prototypes = prototypes()
        self.session = ort.InferenceSession(str(ROOT / 'model/onnx/vision_model_int8.onnx'), sess_options=options(), providers=['CPUExecutionProvider'])

    def embed(self, path):
        with Image.open(path) as image:
            image = ImageOps.exif_transpose(image).convert('RGB')
            w, h = image.size
            if max(w, h) / min(w, h) > 12:
                raise ValueError('Aspect ratio exceeds bounded encoder preprocessing')
            scale = 224 / min(w, h)
            image = image.resize((int(w * scale), int(h * scale)), Image.Resampling.BICUBIC)
            x = (image.width - 224) // 2
            y = (image.height - 224) // 2
            image = image.crop((x, y, x + 224, y + 224))
            array = np.asarray(image, dtype=np.float32) / 255.0
            array = (array - np.array([0.48145466, 0.4578275, 0.40821073], dtype=np.float32)) / np.array([0.26862954, 0.26130258, 0.27577711], dtype=np.float32)
            array = np.transpose(array, (2, 0, 1))[None, ...].copy()
        return unit(self.session.run(None, {'pixel_values': array})[0])[0]

    def describe(self, path):
        start = time.perf_counter()
        vector = self.embed(path)
        scores = sorted([{'family': f, 'label': p['label'], 'score': float(np.max(np.array(p['vectors'], dtype=np.float32) @ vector))} for f, p in self.prototypes.items()], key=lambda x: x['score'], reverse=True)
        family = scores[0]['family']
        best_object = TYPES[family][1][int(np.argmax(np.array(self.prototypes[family]['vectors'], dtype=np.float32) @ vector))]
        return {'object': best_object, 'embedding': vector.tolist(), 'family': scores[0]['family'], 'label': scores[0]['label'], 'score': scores[0]['score'], 'margin': scores[0]['score'] - scores[1]['score'], 'top': scores[:3], 'ms': (time.perf_counter() - start) * 1000}

def serve(port, token_path):
    import io, hmac, hashlib
    from http.server import HTTPServer, BaseHTTPRequestHandler
    from PIL import UnidentifiedImageError
    expected = {'onnx/vision_model_int8.onnx': '0ab0c1b3ace708e539633af1744d5a95247fe4e14d3e08ff197ef82a6cb9bd93', 'onnx/text_model_int8.onnx': '18845f2ccc35223bb7fec403383a131154b11ac0918df25cf51986df5efd3a21'}
    for name, digest in expected.items():
        with (ROOT / 'model' / name).open('rb') as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() != digest:
                raise RuntimeError('Model integrity verification failed')
    token = token_path.read_text().strip()
    if len(token) < 32:
        raise RuntimeError('Private runtime key is required')
    encoder = Encoder()
    Image.MAX_IMAGE_PIXELS = 12000000

    class Server(HTTPServer):
        request_queue_size = 2

        def get_request(self):
            sock, address = super().get_request()
            sock.settimeout(2)
            return (sock, address)

    class Handler(BaseHTTPRequestHandler):

        def reply(self, status, data):
            body = json.dumps(data, allow_nan=False).encode()
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.send_header('Cache-Control', 'no-store')
            self.end_headers()
            self.wfile.write(body)

        def do_GET(self):
            if self.path != '/health':
                self.reply(404, {})
                return
            self.reply(200, {'version': VERSION, 'ready': True, 'memory': memory()})

        def do_POST(self):
            if self.path != '/embed' or not hmac.compare_digest(self.headers.get('X-Zefanya-Visual-Key', ''), token):
                self.reply(403, {})
                return
            try:
                size = int(self.headers.get('Content-Length', '0'))
                if size < 1 or size > 5 * 1024 * 1024:
                    self.reply(413, {})
                    return
                data = self.rfile.read(size)
                if len(data) != size:
                    self.reply(400, {})
                    return
                with Image.open(io.BytesIO(data)) as image:
                    if image.format not in ['JPEG', 'PNG', 'WEBP'] or max(image.size) > 5000 or image.width * image.height > 12000000:
                        self.reply(422, {})
                        return
                    image.verify()
                result = encoder.describe(io.BytesIO(data))
                result.pop('top', None)
                result['version'] = VERSION
                self.reply(200, result)
            except (ValueError, UnidentifiedImageError, Image.DecompressionBombError, OSError):
                self.reply(422, {})
            except (MemoryError, RuntimeError):
                self.reply(503, {})

        def log_message(self, *args):
            pass
    print(json.dumps({'ready': True, 'version': VERSION, 'port': port, 'memory': memory()}), flush=True)
    Server(('127.0.0.1', port), Handler).serve_forever()
if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--home', type=Path, default=ROOT)
    parser.add_argument('--port', type=int, default=8766)
    parser.add_argument('--token-file', type=Path)
    args = parser.parse_args()
    ROOT = args.home
    serve(args.port, args.token_file or ROOT.parent / 'clip-runtime.key')
