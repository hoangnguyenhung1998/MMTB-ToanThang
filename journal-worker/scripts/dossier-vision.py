"""One private dossier request. Reuses check-in transport, never starts its worker/API."""
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src"))
from mmtb_journal_worker.vision_client import JournalVisionClient, VisionError


def main():
    request = json.load(sys.stdin)
    cfg = request.get("configuration", {})
    values = [str(cfg.get(k, "")).strip() for k in ("endpoint", "key", "model")]
    if not all(values):
        print(json.dumps({"error": "missing_configuration", "retryable": False}))
        return 2
    provider = "journal-compatible-dossiers"
    transport = JournalVisionClient(*values, timeout_seconds=min(180, max(10, int(cfg.get("timeout", 180)))))
    content = [{"type": "text", "text": request["prompt"]}]
    if request.get("image"):
        content.append({"type": "image_url", "image_url": {"url": transport.image_data_url(Path(request["image"])), "detail": "high"}})
    try:
        result = transport.request_json({"model": transport.model, "messages": [{"role": "user", "content": content}], "temperature": 0, "response_format": {"type": "json_object"}})
        print(json.dumps({"data": result.data, "raw": result.raw_content, "usage": result.usage, "provider": provider, "model": transport.model}, ensure_ascii=False))
        return 0
    except VisionError as exc:
        # Existing transport exceptions can contain response body/PII: never echo them.
        print(json.dumps({"error": "vision_request_failed", "retryable": exc.retryable}))
        return 3

if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception:
        print(json.dumps({"error": "adapter_failed", "retryable": False}))
        sys.exit(2)
