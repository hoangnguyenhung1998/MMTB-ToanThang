"""Guarded real-worker HTTP E2E; fixture DB and local R2-like disk only, AI MOCK."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import uuid
import zipfile
import requests
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'dossier-worker'))
from worker import Api,Worker,Lock
from pdf_engine import PdfEngine,sha
from pypdf import PdfReader
from PIL import Image,ImageChops
ROOT=Path(__file__).resolve().parents[2]
proof=Path(os.environ['P18_ACCEPTANCE_ROOT']).resolve()
if '/.codex/visualizations/' not in proof.as_posix():raise RuntimeError('Private evidence root required')
cfg=json.loads((proof/'http-test-config.json').read_text(encoding='utf-8-sig'))
php=os.environ.get('P18_PHP','C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe')
def fixture(mode):
 p=subprocess.run([php,str(ROOT/'tests/Support/DossierRemoteHttpFixture.php'),mode],capture_output=True,text=True,check=True)
 return json.loads(p.stdout)
base='http://127.0.0.1:8848'
s=requests.Session()
def get(path):
 r=s.get(base+path,timeout=120);r.raise_for_status();return r
r=get('/login')
token=re.search(r'name="_token"[^>]*value="([^"]+)"',r.text)[1]
r=s.post(base+'/login',data={'_token':token,'email':'dossier-e2e@example.test','password':cfg['password']},timeout=60)
r.raise_for_status()
r=get('/dossiers');token=re.search(r'name="csrf-token" content="([^"]+)"',r.text)[1]
headers={'X-CSRF-TOKEN':token,'Accept':'application/json'}
def post(path,data=None,files=None):
 r=s.post(base+path,data=data,files=files,headers=headers,timeout=180)
 if r.status_code>=400:raise RuntimeError('HTTP '+str(r.status_code)+' path='+path+' '+r.text[:500])
 return r
source=ROOT/'storage/app/dossiers-private/sources/be2f7072-b5a6-46d5-9406-6f241417640e.pdf'
expected='f1146e33cc556084822e0221fa4eb2709d2cd2fa65d5c91b63f54f09ae7fb62a'
assert sha(source)==expected and source.stat().st_size==14727818
state=fixture('state')
if state['source']:
 sid=state['source']['id'];print('Resuming preserved isolated E2E fixture',flush=True)
else:
 r=post('/dossiers/batches');batch=re.search(r'/batches/([a-f0-9-]{36})',r.url)[1]
 with source.open('rb')as f:r=post(f'/dossiers/batches/{batch}/files',files={'file':('T-XX0709.pdf',f,'application/pdf')})
 sid=r.json()['id'];assert not r.json()['duplicate'];print('HTTP upload saved source; original hash verified',flush=True)
engine=PdfEngine(os.environ['DOSSIERS_QPDF'],os.environ['DOSSIERS_PDFINFO'],os.environ['DOSSIERS_PDFTOPPM'])
api=Api(base+'/api/dossiers/v1',cfg['worker_token'],'e2e-local')
worker=Worker(api,engine,proof/'worker-state',mock=True)
with Lock(proof/'worker-state'/'worker.lock'):
 if fixture('state')['source']['status']!='ready': assert worker.tick()
 state=fixture('state');src=state['source'];assert src['status']=='ready' and src['page_count']==16
 print('Worker prepare ready16/16 with16thumbnails via authenticated HTTP',flush=True)
 for p in range(1,17):
  r=get(f'/dossiers/sources/{sid}/file/thumbnail/{p}');assert r.headers['Content-Type'].startswith('image/')
  (proof/f'thumbnail-{p}.png').write_bytes(r.content)
 r=get(f'/dossiers/sources/{sid}/file/processed');processed=proof/'real-processed.pdf';processed.write_bytes(r.content)
 assert engine.check(processed)==16 and src['processing']['normalize_exit']==3
 assert engine.geometry(source)==engine.geometry(processed)
 # Full-page pixel equality before/after normalization, same Poppler size.
 pixels=[]
 for p in range(1,17):
  a=engine.render(source,p,proof/f'compare-source-{p}.png');b=engine.render(processed,p,proof/f'compare-processed-{p}.png')
  with Image.open(a)as x,Image.open(b)as y: assert x.size==y.size and ImageChops.difference(x.convert('RGB'),y.convert('RGB')).getbbox() is None
  pixels.append(p)
 print('Source/processed geometry and16render pixel comparisons PASS',flush=True)
 if not fixture('state')['run'] or fixture('state')['run']['status']!='ready':
  post(f'/dossiers/sources/{sid}',{'machine_id':cfg['machine_id'],'revision':src['revision'],'confirm':'0'})
  post(f'/dossiers/sources/{sid}/ai')
  for i in range(17):
   fixture('clear-spacing') # Test-only clock shortcut; production minimum spacing separately asserted.
   assert worker.tick()
   if i%4==3:print(f'Mock AI remote jobs completed {i+1}/17',flush=True)
 state=fixture('state');run=state['run'];assert run['status']=='ready' and len(state['proposals'])==1
 proposal=state['proposals'][0];src=state['source']
 # Intentionally synthetic review, only in isolated DB. No real dossier metadata inferred.
 selected=[14,15,16,13]
 form={'source_revision':src['revision'],'reviewed_evidence':'1','type_id':cfg['type_id'],'subject':'machine','subject_id':cfg['machine_id'],'instance':'ISOLATED-E2E-ONLY','page_numbers':','.join(map(str,selected)),'shared_pages':'0','effective_at':'2026-01-01T00:00','expiry_precision':'unknown','reason':'Isolated E2E synthetic classification; not validation of real document facts','confirmed':'1','rotations[14]':'90','rotations[15]':'0','rotations[16]':'270','rotations[13]':'0'}
 if not fixture('state')['versions']:
  post(f"/dossiers/ai/proposals/{proposal['id']}/review",form)
  state=fixture('state');v=state['versions'][0];assert v['confirmed']
 state=fixture('state');v=state['versions'][0]
 # Source closure excludes remaining pages intentionally; original bytes remain available.
 data=[('machine_id',str(cfg['machine_id'])),('revision',str(state['source']['revision'])),('confirm','1')]+[('excluded_pages[]',str(p))for p in range(1,17)if p not in selected]
 if not state['source']['confirmed']: post(f'/dossiers/sources/{sid}',data)
 # Snapshot export, then replace before worker merges: output must retain old version/pages.
 export={'version_ids[]':v['id'],'request_id':str(uuid.uuid4()),'action':'create','acknowledged':'1'}
 if not state['exports']:
  r=post(f"/dossiers/machines/{cfg['machine_id']}/exports",export);eid=re.search('/exports/([a-f0-9-]{36})',r.url)[1]
 else: eid=state['exports'][0]['id']
 replacement={**form,'request_id':str(uuid.uuid4()),'source_id':sid,'record_id':v['record_id'],'corrects_id':v['id'],'effective_at':'2026-02-01T00:00','number':'LOCAL-E2E-REPLACEMENT','reason':'Isolated E2E replacement only'}
 if len(fixture('state')['versions'])==1: post('/dossiers/versions',replacement)
 if fixture('state')['exports'][0]['status']!='ready': assert worker.tick()
 state=fixture('state');ex=state['exports'][0];assert ex['status']=='ready' and ex['manifest'][0]['version_id']==v['id'] and len(state['versions'])==2
 output=proof/'real-export.pdf';output.write_bytes(get(f'/dossiers/exports/{eid}/file').content)
 assert sha(output)==ex['sha256'] and engine.check(output)==4
 old_reader=PdfReader(processed);new_reader=PdfReader(output)
 rotations=[90,0,270,0]
 image_checks=[]
 for idx,(page,rot)in enumerate(zip(selected,rotations)):
  expected_page=old_reader.pages[page-1];actual=new_reader.pages[idx]
  assert actual.rotation%360==(expected_page.rotation+rot)%360
  expected_images=[hashlib.sha256(image.data).hexdigest()for image in expected_page.images]
  actual_images=[hashlib.sha256(image.data).hexdigest()for image in actual.images]
  assert expected_images==actual_images
  image_checks.append({'source_page':page,'additional_rotation':rot,'images_identical':True})
  engine.render(output,idx+1,proof/f'export-page-{idx+1}.png')
 excel=proof/'real-export.xlsx';excel.write_bytes(get('/dossiers/excel').content)
 with zipfile.ZipFile(excel)as book:assert len([n for n in book.namelist()if re.fullmatch('xl/worksheets/sheet[0-9]+.xml',n)])==2
 assert sha(source)==expected
 result={'real_file':'T-XX0709.pdf','source_bytes':source.stat().st_size,'source_sha256':expected,'source_pages':16,'thumbnails':16,'normalize_exit':3,'geometry_equal':True,'pixel_equal_pages':pixels,'ai':'MOCK ONLY; no paid call','ai_jobs':17,'versions_in_isolated_db':2,'frozen_manifest_old_version':True,'output_pages':4,'output_order':image_checks,'output_sha256':sha(output),'excel_sheets':2,'storage_adapter_path':'throws; never called','worker_temp_cleanup':not list((proof/'worker-state').glob('job-*'))}
 (proof/'http-e2e-proof.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
 print(json.dumps(result),flush=True)
