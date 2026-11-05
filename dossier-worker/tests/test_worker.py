import json
import os
import re
from pathlib import Path
import subprocess
import struct
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from pdf_engine import PdfEngine, ProcessingError, recoverable_warning, sha
from worker import Api, ApiError, Worker, Lock, PIPELINE
from pypdf import PdfWriter, PdfReader

class PdfTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name)
        self.engine=PdfEngine(os.getenv('DOSSIERS_QPDF','qpdf'),os.getenv('DOSSIERS_PDFINFO','pdfinfo'),os.getenv('DOSSIERS_PDFTOPPM','pdftoppm'))
        w=PdfWriter();w.add_blank_page(300,200);w.add_blank_page(200,300).rotate(90);self.source=self.root/'source.pdf';w.write(self.source)
    def tearDown(self): self.tmp.cleanup()
    def test_only_exact_missing_self_entry_warning_is_recoverable(self):
        warning='WARNING: source.pdf: xref entry for the xref stream itself is missing - a common error handled correctly by qpdf and most other applications\nqpdf: operation succeeded with warnings; resulting file may have some problems'
        self.assertTrue(recoverable_warning(warning));self.assertFalse(recoverable_warning(warning+'\nWARNING: object count differs'));self.assertFalse(recoverable_warning(''))
    def test_pdf16_compressed_prepare_rotations_blank_and_merge_reopen_source_unchanged(self):
        compressed=self.root/'compressed.pdf'
        p=self.engine.run([self.engine.qpdf,'--object-streams=generate','--force-version=1.6',self.source,compressed],'fixture')
        self.assertEqual(0,p.returncode);before=sha(compressed)
        result,outputs=self.engine.prepare(compressed,self.root)
        self.assertEqual(2,result['page_count']);self.assertEqual(result['geometry_before'],result['geometry_after']);self.assertEqual(before,sha(compressed))
        self.assertTrue(outputs['thumbnail-2'].is_file())
        data={'manifest':[{'source_object':'a','processed_object':'b','pages':[{'page':2,'rotation':270},{'page':1,'rotation':90},{'page':2,'rotation':0}]}],'expected_pages':3,'manifest_hash':'fixed'}
        result,out=self.engine.merge(data,{'a':compressed,'b':outputs['processed']},self.root)
        self.assertEqual([0,90,90],[p.rotation for p in PdfReader(out['pdf']).pages]);self.assertEqual('fixed',result['manifest_hash']);self.assertEqual(before,sha(compressed))
    def test_exit2_unknown_warning_output_check_and_timeout_are_not_accepted(self):
        cases=[(2,'error','pdf_structure_error'),(3,'WARNING: unknown','pdf_unknown_warning')]
        for code,error,expected in cases:
            with patch.object(self.engine,'run',side_effect=lambda args,stage,*rest:subprocess.CompletedProcess(args,code,'qpdf version 12.3.2',error)),patch.object(self.engine,'geometry',return_value=[['size']]):
                with self.assertRaisesRegex(ProcessingError,expected):self.engine.prepare(self.source,self.root)
        with patch('subprocess.run',side_effect=subprocess.TimeoutExpired('qpdf',1)):
            with self.assertRaisesRegex(ProcessingError,'pdf_timeout'):self.engine.run(['qpdf'],'normalize')
        with patch.object(self.engine,'run',return_value=subprocess.CompletedProcess([],3,'','warning')):
            with self.assertRaisesRegex(ProcessingError,'pdf_derivative_invalid'):self.engine.check(self.source)
    def test_real_missing_xref_self_entry_fixture_recovered_only_on_verified_version(self):
        content=b'BT /F1 14 Tf 20 100 Td (SELF ENTRY REGRESSION) Tj ET'
        objects=[b'<< /Type /Catalog /Pages 2 0 R >>',b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',b'<< /Length '+str(len(content)).encode()+b' >>\nstream\n'+content+b'\nendstream',b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>']
        data=b'%PDF-1.6\n';offsets=[]
        for n,body in enumerate(objects,1):offsets.append(len(data));data+=str(n).encode()+b' 0 obj\n'+body+b'\nendobj\n'
        start=len(data);xref=bytes([0])+struct.pack('>IH',0,65535)
        for offset in offsets:xref+=bytes([1])+struct.pack('>IH',offset,0)
        data+=b'6 0 obj\n<< /Type /XRef /Root 1 0 R /Size 7 /W [1 4 2] /Index [0 6] /Length '+str(len(xref)).encode()+b' >>\nstream\n'+xref+b'\nendstream\nendobj\nstartxref\n'+str(start).encode()+b'\n%%EOF\n'
        source=self.root/'missing-self.pdf';source.write_bytes(data);before=sha(source)
        version=self.engine.run([self.engine.qpdf,'--version'],'version').stdout
        match=re.search(r'version ([0-9.]+)',version)
        if tuple(map(int,match[1].split('.'))) < (12,3,2):
            with self.assertRaisesRegex(ProcessingError,'pdf_unknown_warning'):self.engine.prepare(source,self.root)
        else:
            result,outputs=self.engine.prepare(source,self.root)
            self.assertEqual(3,result['normalize_exit']);self.assertEqual(['missing_xref_self_entry'],result['warnings']);self.assertEqual(1,self.engine.check(outputs['processed']))
        self.assertEqual(before,sha(source))

    def test_corrupt_and_encrypted_sources_are_rejected(self):
        w=PdfWriter();w.add_blank_page(100,100);w.encrypt('private');p=self.root/'encrypted.pdf';w.write(p)
        with self.assertRaises(ProcessingError): self.engine.prepare(p,self.root)
        p.write_bytes(b'%PDF-1.6 broken')
        with self.assertRaises(ProcessingError): self.engine.prepare(p,self.root)

class TransportTests(unittest.TestCase):
    def setUp(self):self.api=Api('http://127.0.0.1:54321/api/dossiers/v1','x'*40,'test')
    def test_reject_http_remote_urls_and_invalid_paths(self):
        with self.assertRaises(ProcessingError):Api('http://hosting/api','x'*40,'test')
        with self.assertRaises(ProcessingError):self.api.request('GET','/../secret')
    def test_download_interrupted_and_checksum_wrong_never_leave_accepted_input(self):
        with tempfile.TemporaryDirectory()as folder:
            path=Path(folder)/'source.pdf';response=Mock();response.__enter__=Mock(return_value=response);response.__exit__=Mock(return_value=False);response.iter_content.return_value=[b'bad']
            with patch.object(self.api,'request',return_value=response):
                with self.assertRaisesRegex(ProcessingError,'source_checksum_mismatch'):self.api.download({'id':'job'},{'id':'11111111-1111-4111-8111-111111111111','bytes':3,'sha256':'0'*64},path)
            self.assertFalse(path.exists());self.assertFalse(path.with_suffix('.partial').exists())
    def test_crash_after_upload_before_complete_replays_identical_outbox_and_ack_cleans_only_job(self):
        with tempfile.TemporaryDirectory()as folder:
            root=Path(folder);work=root/'job';work.mkdir();pdf=work/'output.pdf';pdf.write_bytes(b'%PDF fixture')
            worker=object.__new__(Worker);worker.api=self.api
            job={'id':'test','attempt':1,'lease_seconds':180}
            receipt={'outputs':{'pdf':'output.pdf'},'payload':{'pipeline':PIPELINE}}
            with patch.object(self.api,'upload')as upload,patch.object(self.api,'json',side_effect=[ApiError('api_network_error',True),{'status':'succeeded'}]):
                with self.assertRaises(ApiError):worker.publish(job,work,receipt)
                self.assertTrue(pdf.exists());worker.publish(job,work,receipt);self.assertEqual(1,upload.call_count)
            self.assertFalse(work.exists());self.assertTrue(root.exists())
    def test_process_lock_blocks_duplicate_worker(self):
        with tempfile.TemporaryDirectory()as folder:
            path=Path(folder)/'worker.lock'
            with Lock(path):
                with self.assertRaisesRegex(ProcessingError,'duplicate_worker_process'):
                    with Lock(path):pass
    def test_missing_ai_configuration_never_calls_transport(self):
        worker=object.__new__(Worker);worker.ai_ready=False
        with self.assertRaisesRegex(ProcessingError,'ai_unconfigured'):worker.read_ai('prompt')

if __name__=='__main__':unittest.main()
