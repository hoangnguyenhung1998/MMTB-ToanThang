from __future__ import annotations
import base64
from email.utils import parsedate_to_datetime
from pathlib import Path
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build
from .auth import SCOPES

class GmailClient:
    def __init__(self,root:Path):
        token=root / "token.json"
        if not token.exists(): raise FileNotFoundError("Missing token.json. Run mmtb-gmail-intake-auth first.")
        credentials=Credentials.from_authorized_user_file(str(token),SCOPES)
        if credentials.expired and credentials.refresh_token: credentials.refresh(Request()); token.write_text(credentials.to_json(),encoding="utf-8")
        self.service=build("gmail","v1",credentials=credentials,cache_discovery=False)

    def list_ids(self,query:str)->list[str]:
        result=self.service.users().messages().list(userId="me",q=query,maxResults=100).execute()
        return [item["id"] for item in result.get("messages",[])]

    def message(self,message_id:str)->dict:
        raw=self.service.users().messages().get(userId="me",id=message_id,format="full").execute()
        headers={h["name"].lower():h["value"] for h in raw.get("payload",{}).get("headers",[])}
        body,attachments=self._parts(message_id,raw.get("payload",{}))
        received=None
        if headers.get("date"):
            try: received=parsedate_to_datetime(headers["date"]).isoformat()
            except (TypeError,ValueError): pass
        return {"gmail_message_id":raw["id"],"gmail_thread_id":raw.get("threadId"),"sender":headers.get("from"),"subject":headers.get("subject"),"body_text":body,"received_at":received,"attachments":attachments}

    def _parts(self,message_id:str,payload:dict)->tuple[str,list[dict]]:
        bodies=[]; html_bodies=[]; attachments=[]
        def walk(part:dict)->None:
            mime=part.get("mimeType",""); body=part.get("body",{}); filename=part.get("filename","")
            data=body.get("data")
            if mime=="text/plain" and data: bodies.append(self._decode(data).decode("utf-8",errors="replace"))
            if mime=="text/html" and data: html_bodies.append(self._decode(data).decode("utf-8",errors="replace"))
            if filename and body.get("attachmentId") and (mime.startswith("image/") or mime=="application/pdf"):
                item=self.service.users().messages().attachments().get(userId="me",messageId=message_id,id=body["attachmentId"]).execute()
                attachments.append({"name":filename,"mime":mime,"bytes":self._decode(item["data"])})
            for child in part.get("parts",[]): walk(child)
        walk(payload)
        if not bodies and html_bodies:
            import re
            bodies.append(re.sub(r"<[^>]+>"," ","\n".join(html_bodies)))
        return "\n".join(bodies),attachments

    @staticmethod
    def _decode(value:str)->bytes: return base64.urlsafe_b64decode(value + "=" * (-len(value)%4))
