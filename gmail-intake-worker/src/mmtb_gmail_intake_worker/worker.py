from __future__ import annotations
import base64,json,logging,time
from pathlib import Path
import requests
from .config import Settings
from .extractor import AssetCodeExtractor
from .gmail_client import GmailClient

class Worker:
    def __init__(self,settings:Settings):
        self.settings=settings; self.gmail=GmailClient(settings.root); self.extractor=AssetCodeExtractor(settings.vision_api_base_url,settings.vision_api_key,settings.vision_model,settings.vision_timeout_seconds)
        self.seen_path=settings.root / "data" / "seen.json"; self.seen_path.parent.mkdir(parents=True,exist_ok=True); self.seen=set(json.loads(self.seen_path.read_text()) if self.seen_path.exists() else [])
        self.http=requests.Session(); self.http.headers.update({"Authorization":f"Bearer {settings.api_token}","Accept":"application/json","User-Agent":"mmtb-gmail-intake-worker/0.1.0"})
    def step(self)->int:
        query=self.settings.gmail_query or f"newer_than:{self.settings.lookback_days}d -from:me subject:TN-"
        count=0
        for message_id in reversed(self.gmail.list_ids(query)):
            if message_id in self.seen: continue
            message=self.gmail.message(message_id); code,confidence,attachments=self.extractor.extract(message["body_text"],message["attachments"])
            evidence=next((item for item in attachments if item["mime"].startswith("image/") and len(item["bytes"]) <= 8 * 1024 * 1024),None)
            payload={key:value for key,value in message.items() if key!="attachments"}; payload.update({"candidate_asset_code":code,"confidence":confidence,"metadata":{"attachment_count":len(attachments)}})
            if evidence: payload.update({"evidence_name":evidence["name"],"evidence_mime":evidence["mime"],"evidence_base64":base64.b64encode(evidence["bytes"]).decode("ascii")})
            response=self.http.post(f"{self.settings.api_url}/replies",json=payload,timeout=60); response.raise_for_status(); result=response.json()["reply"]
            logging.info("Processed Gmail %s: case=%s code=%s status=%s",message_id,result.get("case_reference"),result.get("candidate_asset_code"),result.get("status")); self.seen.add(message_id); self._save(); count+=1
        return count
    def _save(self)->None:
        temporary=self.seen_path.with_suffix(f".{int(time.time()*1000)}.tmp"); temporary.write_text(json.dumps(sorted(self.seen)),encoding="utf-8"); temporary.replace(self.seen_path)
