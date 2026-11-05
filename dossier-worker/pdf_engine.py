"""Dossier-only native PDF engine. No source mutation, rasterization or downsampling."""
from __future__ import annotations
import hashlib
import os
from pathlib import Path
import re
import subprocess
from pypdf import PdfReader, PdfWriter

class ProcessingError(RuntimeError):
    def __init__(self, code, retryable=False):
        super().__init__(code)
        self.code, self.retryable = code, retryable

def sha(path):
    h = hashlib.sha256()
    with Path(path).open('rb') as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()

def recoverable_warning(stderr):
    found = False
    for line in stderr.strip().splitlines():
        if line.startswith('WARNING: ') and line.endswith('xref entry for the xref stream itself is missing - a common error handled correctly by qpdf and most other applications'):
            found = True
        elif line.strip() and line != 'qpdf: operation succeeded with warnings; resulting file may have some problems':
            return False
    return found

class PdfEngine:
    def __init__(self, qpdf='qpdf', pdfinfo='pdfinfo', pdftoppm='pdftoppm', diagnostic=None):
        self.qpdf, self.pdfinfo, self.pdftoppm = qpdf, pdfinfo, pdftoppm
        self.diagnostic = diagnostic or (lambda *a: None)
        self.progress = lambda page: None

    def run(self, args, stage, timeout=120):
        try:
            p = subprocess.run([str(x) for x in args], capture_output=True, timeout=timeout, env={**os.environ, 'LC_ALL': 'C'}, encoding='utf-8', errors='replace', creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
        except subprocess.TimeoutExpired:
            self.diagnostic(stage, None, 'timeout')
            raise ProcessingError('pdf_timeout', True) from None
        except OSError:
            self.diagnostic(stage, None, 'launch_failed')
            raise ProcessingError('pdf_tool_unavailable') from None
        # Metadata/paths/scan text are never logged. Explicit safe status only.
        self.diagnostic(stage, p.returncode, 'finished')
        return p

    def preflight(self):
        versions = {}
        for key, command in [('qpdf', self.qpdf), ('pdfinfo', self.pdfinfo), ('pdftoppm', self.pdftoppm)]:
            p = self.run([command, '--version' if key == 'qpdf' else '-v'], key, 10)
            if p.returncode: raise ProcessingError('pdf_tool_unavailable')
            text = p.stdout + p.stderr
            match = re.search(r'(?:qpdf version|version)\s+([0-9.]+)', text)
            if not match: raise ProcessingError('pdf_tool_version_unknown')
            versions[key] = match[1]
        if tuple(map(int, versions['qpdf'].split('.'))) < (12, 3, 2):
            raise ProcessingError('qpdf_requires_12_3_2')
        return versions

    def geometry(self, path, max_pages=300):
        p = self.run([self.pdfinfo, '-box', '-f', '1', '-l', str(max_pages), path], 'geometry', 60)
        if p.returncode: raise ProcessingError('pdf_geometry_unreadable')
        if re.search(r'^Encrypted:\s+yes', p.stdout, re.M | re.I): raise ProcessingError('pdf_encrypted')
        count = re.search(r'^Pages:\s+(\d+)', p.stdout, re.M)
        if not count or not 1 <= int(count[1]) <= max_pages: raise ProcessingError('pdf_page_limit')
        # One geometry record per page; preserve Poppler page boxes and rotation.
        pages = [[] for _ in range(int(count[1]))]
        for line in p.stdout.splitlines():
            m = re.match(r'^Page\s+(\d+)\s+(size|rot|MediaBox|CropBox|BleedBox|TrimBox|ArtBox):\s*(.*)', line)
            if m: pages[int(m[1])-1].append(m[2] + ':' + ' '.join(m[3].split()))
        if any(len(p) < 4 for p in pages): raise ProcessingError('pdf_geometry_incomplete')
        return pages

    def check(self, path):
        if self.run([self.qpdf, '--check', path], 'check', 90).returncode != 0: raise ProcessingError('pdf_derivative_invalid')
        reader = PdfReader(path, strict=True)
        if reader.is_encrypted: raise ProcessingError('pdf_encrypted')
        for page in reader.pages: page.get_contents()
        return len(reader.pages)

    def render(self, path, page, target, dpi=None):
        args = [self.pdftoppm, '-f', str(page), '-l', str(page), '-singlefile']
        args += ['-r', str(dpi), '-scale-to', '4500' if dpi == 300 else '3300', '-jpeg'] if dpi else ['-scale-to', '1000', '-png']
        p = self.run([*args, path, target.with_suffix('')], 'render', 120)
        if p.returncode or not target.is_file(): raise ProcessingError('pdf_render_failed')
        return target

    def prepare(self, source, work, max_pages=300):
        original_sha = sha(source)
        geometry = self.geometry(source, max_pages)
        version = self.run([self.qpdf, '--version'], 'qpdf_version', 10).stdout.strip()
        processed = work / 'processed.pdf'
        p = self.run([self.qpdf, '--object-streams=disable', '--stream-data=preserve', '--deterministic-id', source, processed], 'normalize')
        if p.returncode == 3 and not recoverable_warning(p.stderr): raise ProcessingError('pdf_unknown_warning')
        if p.returncode not in (0, 3): raise ProcessingError('pdf_structure_error' if p.returncode == 2 else 'pdf_normalize_failed')
        count = self.check(processed)
        after = self.geometry(processed, max_pages)
        if geometry != after or count != len(geometry): raise ProcessingError('pdf_geometry_changed')
        trial = work / 'trial.pdf'
        writer = PdfWriter()
        writer.append(processed)
        writer.write(trial)
        trial_count = self.check(trial)
        if trial_count != count or self.geometry(trial, max_pages) != geometry: raise ProcessingError('pdf_trial_export_failed')
        trial.unlink()
        outputs = {'processed': processed}
        for page in range(1, count+1):
            outputs[f'thumbnail-{page}'] = self.render(processed, page, work / f'thumbnail-{page}.png')
            self.progress(page)
        if sha(source) != original_sha: raise ProcessingError('source_mutated')
        return {'page_count': count, 'geometry_before': geometry, 'geometry_after': after, 'normalize_exit': p.returncode, 'warnings': ['missing_xref_self_entry'] if p.returncode == 3 else [], 'qpdf_version': version, 'validation': {'qpdf_check_exit': 0, 'reopened_pages': count, 'trial_export_pages': trial_count, 'rasterized': False}}, outputs

    def merge(self, data, inputs, work):
        writer = PdfWriter()
        readers = {}
        for item in data['manifest']:
            key = item.get('processed_object') or item['source_object']
            if key not in readers:
                readers[key] = PdfReader(inputs[key], strict=True)
                if readers[key].is_encrypted: raise ProcessingError('pdf_encrypted')
            for selection in item['pages']:
                # add_page clones into the writer; never rotate shared source page objects.
                page = writer.add_page(readers[key].pages[int(selection['page'])-1])
                rotation = int(selection['rotation'])
                if rotation not in (0,90,180,270): raise ProcessingError('manifest_rotation_invalid')
                if rotation: page.rotate(rotation)
                page.rotation = page.rotation % 360
        target = work / 'export.pdf'
        writer.write(target)
        count = self.check(target)
        if count != data['expected_pages']: raise ProcessingError('export_page_mismatch')
        return {'pages': count, 'qpdf_check_exit': 0, 'reopened': True, 'manifest_hash': data['manifest_hash']}, {'pdf': target}
