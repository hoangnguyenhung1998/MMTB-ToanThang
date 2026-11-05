# Test-only native engine bridge; production uses the authenticated polling worker.
import json
from pathlib import Path
import sys
import tempfile
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'dossier-worker'))
from pdf_engine import PdfEngine
request=json.load(sys.stdin)
engine=PdfEngine(*request['binaries'])
# Caller owns temporary folder and publishes outputs through the real protocol.
work=Path(request['work'])
if request['kind']=='prepare': result,outputs=engine.prepare(Path(request['source']),work)
else: result,outputs=engine.merge(request['input'],{k:Path(v) for k,v in request['paths'].items()},work)
print(json.dumps({'result':result,'outputs':{k:str(v) for k,v in outputs.items()}}))
