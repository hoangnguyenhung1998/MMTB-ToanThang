"""Outbound-only, single-job dossier worker. Does not start/load Journal or Collector."""
from __future__ import annotations
import argparse
import hashlib
import json
import logging
from logging.handlers import RotatingFileHandler
import os
from pathlib import Path
import re
import shutil
import sys
import threading
import time
from urllib.parse import urlparse
import uuid
import requests
from pdf_engine import PdfEngine, ProcessingError, sha

ROOT = Path(__file__).resolve().parent
PIPELINE = 'dossier-remote-v2'
LOG = logging.getLogger('dossiers')

def digest(data):
    encoded=json.dumps(data,sort_keys=True,separators=(',',':'),ensure_ascii=False).replace('\u2028','\\u2028').replace('\u2029','\\u2029')
    return hashlib.sha256(encoded.encode('utf-8')).hexdigest()

def load_env(path):
    # Read this worker's file only; environment takes precedence. No interpolation.
    if path.is_file():
        for line in path.read_text(encoding='utf-8-sig').splitlines():
            if not line.strip() or line.lstrip().startswith('#'): continue
            key, sep, value = line.partition('=')
            if sep and re.fullmatch(r'DOSSIERS_[A-Z_]+', key.strip()): os.environ.setdefault(key.strip(), value.strip().strip('"\''))

class ApiError(ProcessingError): pass

class Api:
    def __init__(self, url, token, worker, maximum=256*1024*1024):
        p = urlparse(url)
        if p.scheme != 'https' and not (p.scheme == 'http' and p.hostname in ('127.0.0.1','localhost','::1')): raise ProcessingError('https_api_required')
        if p.username or p.password or p.query or p.fragment: raise ProcessingError('invalid_api_url')
        if len(token) < 32 or not re.fullmatch(r'[A-Za-z0-9_.-]{1,100}', worker): raise ProcessingError('worker_configuration_missing')
        self.url, self.token, self.worker, self.maximum = url.rstrip('/'), token, worker, maximum

    def headers(self, job=None):
        h = {'Authorization': 'Bearer '+self.token, 'Accept':'application/json'}
        if job: h.update({'X-Dossier-Worker':self.worker,'X-Dossier-Claim':job['claim_token'],'X-Dossier-Attempt':str(job['attempt'])})
        return h

    def request(self, method, path, job=None, **kwargs):
        # Only server-independent, generated API paths; reject redirects to prevent credential leaks.
        if not re.fullmatch(r'/[A-Za-z0-9_./-]+', path) or '..' in path: raise ProcessingError('unsafe_api_path')
        headers = {**self.headers(job), **kwargs.pop('headers', {})}
        try:
            response = requests.request(method,self.url+path,headers=headers,timeout=(15,180),allow_redirects=False,**kwargs)
        except requests.RequestException: raise ApiError('api_network_error',True) from None
        if not 200 <= response.status_code < 300:
            code = response.status_code
            response.close()
            raise ApiError('claim_rejected' if code in (404,409) else 'api_http_'+str(code),code in (408,429) or code >= 500)
        return response

    def json(self, method, path, payload=None, job=None):
        with self.request(method,path,job,json=payload) as response:
            if int(response.headers.get('Content-Length',0)) > 2*1024*1024: raise ApiError('api_response_limit')
            body = response.content
            if len(body)>2*1024*1024: raise ApiError('api_response_limit')
            try: return json.loads(body)
            except ValueError: raise ApiError('api_invalid_json') from None

    def download(self, job, obj, target):
        oid=str(uuid.UUID(obj['id']))
        if not re.fullmatch('[a-f0-9]{64}',obj['sha256']) or not 0 < obj['bytes'] <= self.maximum: raise ProcessingError('input_metadata_invalid')
        temporary=target.with_suffix('.partial')
        try:
            total=0
            with self.request('GET',f"/jobs/{job['id']}/inputs/{oid}",job,stream=True) as response, temporary.open('wb') as out:
                for chunk in response.iter_content(1024*1024):
                    total+=len(chunk)
                    if total>obj['bytes']: raise ProcessingError('source_size_mismatch')
                    out.write(chunk)
            if total != obj['bytes'] or sha(temporary) != obj['sha256']: raise ProcessingError('source_checksum_mismatch')
            temporary.replace(target)
        except requests.RequestException: raise ApiError('download_interrupted',True) from None
        finally: temporary.unlink(missing_ok=True)
        return target

    def upload(self,job,slot,path):
        if path.stat().st_size>self.maximum: raise ProcessingError('output_size_limit')
        if not re.fullmatch(r'(processed|pdf|image|thumbnail-[1-9][0-9]{0,2})',slot): raise ProcessingError('output_slot_invalid')
        boundary='dossier'+uuid.uuid4().hex
        digest=sha(path)
        mime='application/pdf' if path.suffix=='.pdf' else 'image/png' if path.suffix=='.png' else 'image/jpeg'
        head=(f'--{boundary}\r\nContent-Disposition: form-data; name="sha256"\r\n\r\n{digest}\r\n--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="output{path.suffix}"\r\nContent-Type: {mime}\r\n\r\n').encode()
        tail=f'\r\n--{boundary}--\r\n'.encode()
        class Body:
            def __len__(self): return len(head)+path.stat().st_size+len(tail)
            def __iter__(self):
                yield head
                with path.open('rb') as source:
                    for chunk in iter(lambda:source.read(1024*1024),b''): yield chunk
                yield tail
        with self.request('POST',f"/jobs/{job['id']}/outputs/{slot}",job,data=Body(),headers={'Content-Type':'multipart/form-data; boundary='+boundary}) as response:
            receipt=response.json()
        if receipt['sha256'] != digest or receipt['bytes'] != path.stat().st_size: raise ProcessingError('upload_receipt_mismatch')

class Lock:
    def __init__(self,path): self.path=path
    def __enter__(self):
        self.file=self.path.open('a+b');self.file.seek(0);self.file.write(b'0');self.file.flush();self.file.seek(0)
        try:
            if os.name=='nt':
                import msvcrt
                msvcrt.locking(self.file.fileno(),msvcrt.LK_NBLCK,1)
            else:
                import fcntl
                fcntl.flock(self.file.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        except OSError:
            self.file.close()
            raise ProcessingError('duplicate_worker_process') from None
        return self
    def __exit__(self,*args): self.file.close()

class Worker:
    def __init__(self,api,engine,state,mock=False):
        self.api,self.engine,self.state,self.mock=api,engine,state,mock
        self.endpoint=os.getenv('DOSSIERS_AI_ENDPOINT','').strip();self.key=os.getenv('DOSSIERS_AI_API_KEY','').strip();self.model=os.getenv('DOSSIERS_AI_MODEL','').strip()
        self.ai_ready=mock or bool(self.endpoint and self.key and self.model)
        if mock and urlparse(api.url).hostname not in ('127.0.0.1','localhost','::1'): raise ProcessingError('mock_requires_localhost')
        self.profile=hashlib.sha256(json.dumps([PIPELINE,'mock' if mock else self.endpoint,'mock' if mock else self.model]).encode()).hexdigest() if self.ai_ready else None
        self.versions=engine.preflight()
        self.state.mkdir(parents=True,exist_ok=True)

    def heartbeat(self):
        return self.api.json('POST','/heartbeat',{'worker_id':self.api.worker,'capabilities':['prepare','export']+(['ai_page','ai_group'] if self.ai_ready else []),'versions':{**self.versions,'pipeline':PIPELINE,'python':sys.version.split()[0]},'ai_ready':self.ai_ready,'ai_profile':self.profile,'provider':'mock-only' if self.mock else 'journal-compatible-dossiers','model':'mock' if self.mock else self.model or None})

    def read_ai(self,prompt,image=None):
        if not self.ai_ready: raise ProcessingError('ai_unconfigured')
        if self.mock:
            data={'documents':[{'title':'LOCAL MOCK — manual review only','type_key':'unknown','subject':'unknown','pages':[1],'fields':{},'expiry_absent':False,'permanent_explicit':False,'multiple_documents':False,'insured':[],'scope_text':'Isolated test; not a scan interpretation','issues':['Mock only; human review required']}],'unclassified_pages':[],'suggested_exclusions':[]} if image is None else {'text':'LOCAL MOCK, not extracted from scan','kind':'unknown','rotation':0,'multiple_documents':False,'suspected_duplicate':False,'fragments':[],'fields':{},'issues':['Mock only; human review required']}
            return {'data':data,'usage':{}}
        # Reuse existing adapter; never import Journal config or its worker entry point.
        sys.path.insert(0,str(ROOT.parent/'journal-worker'/'src'))
        from mmtb_journal_worker.vision_client import JournalVisionClient,VisionError
        client=JournalVisionClient(self.endpoint,self.key,self.model,180)
        content=[{'type':'text','text':prompt}]
        if image: content.append({'type':'image_url','image_url':{'url':client.image_data_url(image),'detail':'high'}})
        try: result=client.request_json({'model':self.model,'messages':[{'role':'user','content':content}],'temperature':0,'response_format':{'type':'json_object'}})
        except VisionError as error: raise ProcessingError('ai_request_failed',error.retryable) from None
        return {'data':result.data,'usage':result.usage}

    def process(self,job,work):
        if job['pipeline'] != PIPELINE or str(uuid.UUID(job['id'])) != job['id']: raise ProcessingError('pipeline_incompatible')
        data=job['input'];inputs={}
        if digest(data)!=job['input_hash']: raise ProcessingError('job_input_hash_mismatch')
        objects=data.get('objects',{})
        if isinstance(objects,list): objects={}
        if sum(o['bytes'] for o in objects.values())>self.api.maximum: raise ProcessingError('input_size_limit')
        for descriptor in objects.values(): inputs[descriptor['id']]=self.api.download(job,descriptor,work/(str(uuid.UUID(descriptor['id']))+'.pdf'))
        if job['kind']=='prepare':
            self.stage='normalize'
            return self.engine.prepare(next(iter(inputs.values())),work,data['max_pages'])
        if job['kind']=='export':
            self.stage='merge'
            return self.engine.merge(data,inputs,work)
        started=time.monotonic();outputs={};image=None
        if job['kind']=='ai_page':
            image=self.engine.render(next(iter(inputs.values())),data['page'],work/'image.jpg',data['dpi']);outputs={'image':image}
        elif job['kind']!='ai_group': raise ProcessingError('job_kind_unknown')
        self.stage='ai'
        result={'response':self.read_ai(data['prompt'],image),'elapsed_ms':round((time.monotonic()-started)*1000),'mock':self.mock}
        return result,outputs

    def publish(self,job,work,receipt):
        for slot,filename in receipt['outputs'].items():
            if slot in receipt.get('uploaded',[]): continue
            if Path(filename).name != filename: raise ProcessingError('outbox_path_invalid')
            self.api.upload(job,slot,work/filename)
            receipt['uploaded']=[*receipt.get('uploaded',[]),slot]
            receipt['job']=job
            temp=work/'outbox.tmp';temp.write_text(json.dumps(receipt,ensure_ascii=False),encoding='utf-8');temp.replace(work/'outbox.json')
        response=self.api.json('POST',f"/jobs/{job['id']}/complete",receipt['payload'],job)
        if response.get('status')!='succeeded': raise ProcessingError('completion_not_acknowledged')
        # This directory is a worker-owned UUID/attempt sandbox, never object storage.
        shutil.rmtree(work)

    def execute(self,job,work):
        stop=threading.Event();lost=threading.Event()
        self.stage='download';self.progress=0
        self.engine.progress=lambda page: (setattr(self,'stage','thumbnail'),setattr(self,'progress',page))
        self.engine.diagnostic=lambda stage,code,status: LOG.info('job=%s attempt=%s stage=%s exit=%s status=%s',job['id'],job['attempt'],stage,code,status)
        LOG.info('job=%s attempt=%s status=started',job['id'],job['attempt'])
        def renew():
            while not stop.wait(max(5,job['lease_seconds']//3)):
                try:
                    self.api.json('POST',f"/jobs/{job['id']}/renew",{'stage':'upload' if (work/'outbox.json').exists() else self.stage,'progress':self.progress},job)
                except ProcessingError as e:
                    if not e.retryable: lost.set();return
        thread=threading.Thread(target=renew,daemon=True);thread.start()
        try:
            box=work/'outbox.json'
            if box.exists(): receipt=json.loads(box.read_text(encoding='utf-8'))
            else:
                result,outputs=self.process(job,work)
                receipt={'job':job,'outputs':{key:path.name for key,path in outputs.items()},'payload':{'input_hash':job['input_hash'],'pipeline':PIPELINE,'result':result}}
                temp=work/'outbox.tmp';temp.write_text(json.dumps(receipt,ensure_ascii=False),encoding='utf-8');temp.replace(box)
            if lost.is_set(): raise ApiError('claim_rejected')
            self.publish(job,work,receipt)
            LOG.info('job=%s attempt=%s status=succeeded',job['id'],job['attempt'])
        except ProcessingError as e:
            LOG.warning('job=%s attempt=%s code=%s retryable=%s',job['id'],job['attempt'],e.code,e.retryable)
            if e.code=='claim_rejected':
                # Confirmed stale claim: retain no local personal data; server owns original.
                shutil.rmtree(work)
            elif (work/'outbox.json').exists() and e.retryable:
                pass  # Crash/network after upload: replay identical files + complete before claiming again.
            else:
                try:
                    ack=self.api.json('POST',f"/jobs/{job['id']}/fail",{'code':e.code,'retryable':e.retryable},job)
                    if ack.get('status') in ('pending','failed'): shutil.rmtree(work)
                except ProcessingError: pass
                # No automatic deletion before fail ACK; next claim handles expiry safely.
        except Exception:
            LOG.error('job=%s attempt=%s code=worker_processing_failed',job['id'],job['attempt'])
            try:
                ack=self.api.json('POST',f"/jobs/{job['id']}/fail",{'code':'worker_processing_failed','retryable':False},job)
                if ack.get('status')=='failed': shutil.rmtree(work)
            except ProcessingError: pass
        finally: stop.set();thread.join(timeout=2)

    def tick(self):
        self.heartbeat()
        for orphan in self.state.glob('job-*'):
            if orphan.is_dir() and not orphan.is_symlink() and re.fullmatch(r'job-[a-f0-9-]{36}-[1-9][0-9]*',orphan.name) and not (orphan/'outbox.json').exists() and time.time()-orphan.stat().st_mtime>86400:
                shutil.rmtree(orphan) # Only disposable worker-owned copies, never R2/legacy originals.
        # Recover one durable outbox before accepting another job.
        for box in sorted(self.state.glob('job-*/outbox.json')):
            receipt=json.loads(box.read_text(encoding='utf-8'))
            self.execute(receipt['job'],box.parent)
            return True
        response=self.api.json('POST','/jobs/claim',{'worker_id':self.api.worker})
        job=response.get('job')
        if not job: return False
        str(uuid.UUID(job['id']))
        work=self.state/('job-'+job['id']+'-'+str(int(job['attempt'])))
        work.mkdir(exist_ok=True,mode=0o700)
        self.execute(job,work)
        return True

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--env',type=Path,default=ROOT/'.env');parser.add_argument('--preflight',action='store_true');parser.add_argument('--once',action='store_true');parser.add_argument('--mock-ai',action='store_true');args=parser.parse_args()
    load_env(args.env)
    if os.name=='nt':
        import ctypes
        ctypes.windll.kernel32.SetPriorityClass(ctypes.windll.kernel32.GetCurrentProcess(),0x00004000) # BELOW_NORMAL, only this worker.
    else:
        os.nice(5)
    state=Path(os.getenv('DOSSIERS_WORKER_STATE','data'))
    state=(state if state.is_absolute() else ROOT/state).resolve();state.mkdir(parents=True,exist_ok=True,mode=0o700)
    if os.name!='nt': os.chmod(state,0o700)
    handler=RotatingFileHandler(state/'worker.log',maxBytes=2*1024*1024,backupCount=5,encoding='utf-8');handler.setFormatter(logging.Formatter('%(asctime)s %(levelname)s %(message)s'));LOG.addHandler(handler);LOG.setLevel(logging.INFO)
    engine=PdfEngine(os.getenv('DOSSIERS_QPDF','qpdf'),os.getenv('DOSSIERS_PDFINFO','pdfinfo'),os.getenv('DOSSIERS_PDFTOPPM','pdftoppm'),lambda stage,code,status: LOG.info('stage=%s exit=%s status=%s',stage,code,status))
    if args.preflight:
        print(json.dumps({'pdf':engine.preflight(),'ai_configured':all(os.getenv(k) for k in ('DOSSIERS_AI_ENDPOINT','DOSSIERS_AI_API_KEY','DOSSIERS_AI_MODEL')),'api_configured':bool(os.getenv('DOSSIERS_API_URL') and os.getenv('DOSSIERS_WORKER_TOKEN'))}));return
    api=Api(os.getenv('DOSSIERS_API_URL',''),os.getenv('DOSSIERS_WORKER_TOKEN',''),os.getenv('DOSSIERS_WORKER_ID',''),int(os.getenv('DOSSIERS_MAX_DOWNLOAD_MB','256'))*1024*1024)
    with Lock(state/'worker.lock'):
        LOG.info('worker_pid=%s status=started',os.getpid())
        worker=Worker(api,engine,state,args.mock_ai)
        while True:
            try: worker.tick()
            except ProcessingError as e: LOG.warning('poll code=%s',e.code)
            if args.once: return
            time.sleep(max(5,int(os.getenv('DOSSIERS_POLL_SECONDS','10'))))

if __name__=='__main__':
    try: main()
    except (ProcessingError,KeyboardInterrupt) as e:
        if isinstance(e,ProcessingError): print('Dossier worker: '+e.code,file=sys.stderr)
        sys.exit(1)
