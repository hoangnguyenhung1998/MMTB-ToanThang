from __future__ import annotations

import logging
import threading
import time
from collections.abc import Callable

from .api_client import LaravelOcrClient, WorkerApiError


LOGGER = logging.getLogger("mmtb_ocr_worker")


class LeaseOwnershipLost(RuntimeError):
    pass


class LeaseHeartbeat:
    def __init__(
        self,
        client: LaravelOcrClient,
        job_id: int,
        attempt: int,
        worker_id: str,
        lease_seconds: int,
        interval_seconds: float,
        monotonic: Callable[[], float] = time.monotonic,
    ):
        self.client = client
        self.job_id = job_id
        self.attempt = attempt
        self.worker_id = worker_id
        self.lease_seconds = max(1, lease_seconds)
        self.interval_seconds = min(max(0.01, interval_seconds), max(0.01, self.lease_seconds / 3))
        self.monotonic = monotonic
        self._last_success = monotonic()
        self._stop = threading.Event()
        self._lost = threading.Event()
        self._thread: threading.Thread | None = None

    @property
    def lost(self) -> bool:
        return self._lost.is_set()

    def start(self) -> None:
        if self._thread is not None:
            raise RuntimeError("Lease heartbeat has already been started.")
        self._thread = threading.Thread(
            target=self._run,
            name=f"ocr-lease-{self.job_id}-{self.attempt}",
            daemon=True,
        )
        self._thread.start()

    def assert_owned(self) -> None:
        if self.lost:
            raise LeaseOwnershipLost(
                f"OCR job {self.job_id} attempt {self.attempt} no longer owns its lease."
            )

    def prepare_finalization(self) -> None:
        self.stop()
        self.assert_owned()
        try:
            renewed = self.client.renew(self.job_id, self.attempt)
        except WorkerApiError as exc:
            self._lost.set()
            raise LeaseOwnershipLost(
                f"Could not prove ownership before finalizing OCR job {self.job_id} "
                f"attempt {self.attempt}: {exc}"
            ) from exc
        self._last_success = self.monotonic()
        LOGGER.info(
            "OCR lease renewed before finalization job_id=%s attempt=%s worker_id=%s lease_expires_at=%s",
            self.job_id,
            self.attempt,
            self.worker_id,
            renewed.get("lease_expires_at", "?"),
        )

    def stop(self) -> None:
        self._stop.set()
        if self._thread is not None and self._thread is not threading.current_thread():
            self._thread.join(timeout=max(1.0, self.client.timeout_seconds + 1.0))
            if self._thread.is_alive():
                self._lost.set()
                LOGGER.error(
                    "OCR lease heartbeat did not stop cleanly job_id=%s attempt=%s worker_id=%s",
                    self.job_id,
                    self.attempt,
                    self.worker_id,
                )

    def _run(self) -> None:
        while not self._stop.wait(self.interval_seconds):
            try:
                renewed = self.client.renew(self.job_id, self.attempt)
                self._last_success = self.monotonic()
                LOGGER.info(
                    "OCR lease renewed job_id=%s attempt=%s worker_id=%s lease_expires_at=%s",
                    self.job_id,
                    self.attempt,
                    self.worker_id,
                    renewed.get("lease_expires_at", "?"),
                )
            except WorkerApiError as exc:
                elapsed = self.monotonic() - self._last_success
                LOGGER.warning(
                    "OCR lease renewal failed job_id=%s attempt=%s worker_id=%s retryable=%s "
                    "seconds_since_success=%.1f error=%s",
                    self.job_id,
                    self.attempt,
                    self.worker_id,
                    exc.retryable,
                    elapsed,
                    exc,
                )
                if not exc.retryable or elapsed >= self.lease_seconds - self.interval_seconds:
                    self._lost.set()
                    return
