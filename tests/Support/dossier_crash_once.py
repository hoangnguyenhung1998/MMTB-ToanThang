"""Isolated fault injection: actual OS crash after HTTP uploads, before complete."""
import json
import os
from pathlib import Path
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'dossier-worker'))
from worker import Api,Worker,Lock,load_env
from pdf_engine import PdfEngine
proof=Path(os.environ['P18_ACCEPTANCE_ROOT']).resolve()
if '/.codex/visualizations/' not in proof.as_posix():raise RuntimeError('Private test evidence root required')
load_env(proof/'crash-worker.env')
api=Api(os.environ['DOSSIERS_API_URL'],os.environ['DOSSIERS_WORKER_TOKEN'],os.environ['DOSSIERS_WORKER_ID'])
normal=api.json
def crash(method,path,*args,**kwargs):
    if path.endswith('/complete'):os._exit(97)
    return normal(method,path,*args,**kwargs)
api.json=crash
state=Path(os.environ['DOSSIERS_WORKER_STATE'])
worker=Worker(api,PdfEngine(os.environ['DOSSIERS_QPDF'],os.environ['DOSSIERS_PDFINFO'],os.environ['DOSSIERS_PDFTOPPM']),state,mock=True)
with Lock(state/'worker.lock'):worker.tick()
raise RuntimeError('Expected process crash was not reached')
