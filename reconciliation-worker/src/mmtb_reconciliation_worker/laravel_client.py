from __future__ import annotations

import tempfile
from pathlib import Path
from urllib.parse import urljoin, urlsplit

import requests


class WorkerApiError(RuntimeError):
    def __init__(self, message: str, retryable: bool):
        super().__init__(message)
        self.retryable = retryable


class LaravelReconciliationClient:
    def __init__(self, api_url: str, token: str, worker_id: str, timeout_seconds: int,
                 temp_dir: Path, session: requests.Session | None = None):
        self.api_url = api_url.rstrip("/")
        self.worker_id = worker_id
        self.timeout_seconds = timeout_seconds
        self.temp_dir = temp_dir
        self.temp_dir.mkdir(parents=True, exist_ok=True)
        self.session = session or requests.Session()
        self.session.headers.update({
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
            "User-Agent": "mmtb-reconciliation-worker/0.1.0",
        })

    def claim(self, work_date: str, limit: int) -> list[dict]:
        response = self._request("POST", "/reconciliation/jobs/claim", json={
            "worker_id": self.worker_id,
            "work_date": work_date,
            "limit": limit,
        })
        if response.status_code == 204:
            return []
        return list(response.json().get("jobs", []))

    def download_image(self, image_url: str, job_id: int, ocr_job_id: int) -> Path:
        response = self._request("GET", self._resolve_image_url(image_url), stream=True, absolute=True)
        suffix = self._image_suffix(response.headers.get("Content-Type", ""))
        with tempfile.NamedTemporaryFile(
            prefix=f"reconciliation-{job_id}-{ocr_job_id}-",
            suffix=suffix,
            dir=self.temp_dir,
            delete=False,
        ) as target:
            for chunk in response.iter_content(chunk_size=1024 * 1024):
                if chunk:
                    target.write(chunk)
            return Path(target.name)

    def complete(self, job_id: int, payload: dict) -> dict:
        response = self._request("POST", f"/reconciliation/jobs/{job_id}/complete", json={
            "worker_id": self.worker_id,
            **payload,
        })
        return response.json()["submission"]

    def fail(self, job_id: int, error: str, retryable: bool) -> dict:
        response = self._request("POST", f"/reconciliation/jobs/{job_id}/fail", json={
            "worker_id": self.worker_id,
            "error": error[:5000],
            "retryable": retryable,
        })
        return response.json()["job"]

    def _request(self, method: str, path: str, absolute: bool = False, **kwargs) -> requests.Response:
        url = path if absolute else f"{self.api_url}{path}"
        try:
            response = self.session.request(method, url, timeout=self.timeout_seconds, **kwargs)
        except requests.RequestException as exc:
            raise WorkerApiError(f"Laravel OpenClaw API request failed: {exc}", retryable=True) from exc
        if not response.ok:
            retryable = response.status_code == 429 or response.status_code >= 500
            raise WorkerApiError(
                f"Laravel OpenClaw API returned {response.status_code}: {response.text[:1000]}",
                retryable=retryable,
            )
        return response

    def _resolve_image_url(self, image_url: str) -> str:
        if urlsplit(image_url).scheme:
            return image_url
        parsed = urlsplit(self.api_url)
        origin = f"{parsed.scheme}://{parsed.netloc}/"
        image_path = urlsplit(image_url).path
        api_suffix = "/api/openclaw/v1"
        deployment_prefix = parsed.path.removesuffix(api_suffix).rstrip("/")
        if image_path.startswith("/api/") and deployment_prefix:
            image_path = f"{deployment_prefix}{image_path}"
        return urljoin(origin, image_path)

    @staticmethod
    def _image_suffix(content_type: str) -> str:
        return {
            "image/png": ".png",
            "image/webp": ".webp",
            "image/bmp": ".bmp",
            "image/tiff": ".tiff",
        }.get(content_type.split(";", 1)[0].strip().lower(), ".jpg")
