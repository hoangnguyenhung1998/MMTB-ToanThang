from __future__ import annotations

import tempfile
from pathlib import Path
from urllib.parse import urljoin, urlsplit

import requests


class WorkerApiError(RuntimeError):
    def __init__(self, message: str, retryable: bool, retry_after_seconds: float | None = None):
        super().__init__(message)
        self.retryable = retryable
        self.retry_after_seconds = retry_after_seconds


class LaravelJournalClient:
    def __init__(
        self,
        api_url: str,
        token: str,
        worker_id: str,
        timeout_seconds: int,
        temp_dir: Path,
        session: requests.Session | None = None,
    ):
        self.api_url = api_url.rstrip("/")
        self.worker_id = worker_id
        self.timeout_seconds = timeout_seconds
        self.temp_dir = temp_dir
        self.temp_dir.mkdir(parents=True, exist_ok=True)
        self.session = session or requests.Session()
        self.session.headers.update({
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
            "User-Agent": "mmtb-journal-worker/0.1.0",
        })

    def machines(self) -> list[dict]:
        response = self._request("GET", "/machines")
        return list(response.json().get("machines", []))

    def claim(self) -> dict | None:
        response = self._request(
            "POST",
            "/jobs/claim",
            json={
                "worker_id": self.worker_id,
                "document_types": ["WEEKLY_JOURNAL"],
            },
        )
        if response.status_code == 204:
            return None
        return response.json()["job"]

    def download_image(self, image_url: str, job_id: int) -> Path:
        url = self._resolve_image_url(image_url)
        try:
            response = self.session.get(url, timeout=self.timeout_seconds, stream=True)
        except requests.RequestException as exc:
            raise WorkerApiError(f"Image download failed: {exc}", retryable=True) from exc
        self._ensure_success(response)

        suffix = self._image_suffix(response.headers.get("Content-Type", ""))
        with tempfile.NamedTemporaryFile(
            prefix=f"journal-job-{job_id}-",
            suffix=suffix,
            dir=self.temp_dir,
            delete=False,
        ) as target:
            for chunk in response.iter_content(chunk_size=1024 * 1024):
                if chunk:
                    target.write(chunk)
            return Path(target.name)

    def complete_journal(self, job_id: int, payload: dict) -> dict:
        response = self._request(
            "POST",
            f"/jobs/{job_id}/complete-journal",
            json={"worker_id": self.worker_id, **payload},
        )
        return response.json()["job"]

    def fail(self, job_id: int, error: str, retryable: bool) -> dict:
        response = self._request(
            "POST",
            f"/jobs/{job_id}/fail",
            json={
                "worker_id": self.worker_id,
                "error": error[:2000],
                "retryable": retryable,
            },
        )
        return response.json()["job"]

    def _request(self, method: str, path: str, **kwargs) -> requests.Response:
        try:
            response = self.session.request(
                method,
                f"{self.api_url}{path}",
                timeout=self.timeout_seconds,
                **kwargs,
            )
        except requests.RequestException as exc:
            raise WorkerApiError(f"Laravel OCR API request failed: {exc}", retryable=True) from exc
        self._ensure_success(response)
        return response

    @staticmethod
    def _ensure_success(response: requests.Response) -> None:
        if response.ok:
            return
        body = response.text[:1000]
        retryable = response.status_code == 429 or response.status_code >= 500
        raise WorkerApiError(
            f"Laravel OCR API returned {response.status_code}: {body}",
            retryable=retryable,
            retry_after_seconds=LaravelJournalClient._retry_after_seconds(response),
        )

    @staticmethod
    def _retry_after_seconds(response: requests.Response) -> float | None:
        if response.status_code != 429:
            return None
        try:
            return max(1.0, min(float(response.headers.get("Retry-After", "60")), 300.0))
        except (TypeError, ValueError):
            return 60.0

    def _resolve_image_url(self, image_url: str) -> str:
        parsed = urlsplit(self.api_url)
        origin = f"{parsed.scheme}://{parsed.netloc}/"
        image_parts = urlsplit(image_url)
        image_path = image_parts.path
        api_suffix = "/api/ocr/v1"
        deployment_prefix = parsed.path.removesuffix(api_suffix).rstrip("/")
        if image_path.startswith("/api/") and deployment_prefix:
            image_path = f"{deployment_prefix}{image_path}"
        if image_parts.query:
            image_path = f"{image_path}?{image_parts.query}"
        return urljoin(origin, image_path)

    @staticmethod
    def _image_suffix(content_type: str) -> str:
        normalized = content_type.split(";", 1)[0].strip().lower()
        return {
            "image/png": ".png",
            "image/webp": ".webp",
            "image/bmp": ".bmp",
            "image/tiff": ".tiff",
        }.get(normalized, ".jpg")
