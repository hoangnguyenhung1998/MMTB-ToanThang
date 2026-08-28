from __future__ import annotations
import base64,json,re

PATTERN=re.compile(r"\b(?:SGC-)?(?:VT|T)-[A-Z0-9-]{4,20}\b",re.I)

class AssetCodeExtractor:
    def __init__(self,base_url:str,key:str,model:str,timeout:int): self.base_url=base_url; self.key=key; self.model=model; self.timeout=timeout
    def extract(self,text:str,attachments:list[dict])->tuple[str|None,float,list[dict]]:
        codes=list(dict.fromkeys(match.group(0).upper().replace(" ","") for match in PATTERN.finditer(text)))
        if len(codes)==1: return codes[0],0.99,attachments
        for attachment in attachments:
            if attachment["mime"].startswith("image/") and self.base_url and self.key and self.model:
                code,confidence=self._vision(attachment)
                if code: return code,confidence,attachments
        return None,0.0,attachments

    def _vision(self,attachment:dict)->tuple[str|None,float]:
        import requests
        instruction="Đọc ảnh phản hồi cấp mã máy. Chỉ lấy đúng mã tài sản nhìn thấy, thường dạng T-XL..., VT-XL..., T-XX..., VT-XX..., T-3C..., SGC-T-3C..., T-LU... Trả JSON {asset_code,confidence}. Không suy đoán."
        payload={"model":self.model,"messages":[{"role":"user","content":[{"type":"text","text":instruction},{"type":"image_url","image_url":{"url":f"data:{attachment['mime']};base64,{base64.b64encode(attachment['bytes']).decode('ascii')}"}}]}],"temperature":0,"response_format":{"type":"json_object"}}
        response=requests.post(f"{self.base_url}/chat/completions",headers={"Authorization":f"Bearer {self.key}"},json=payload,timeout=self.timeout); response.raise_for_status()
        content=response.json()["choices"][0]["message"]["content"]; data=content if isinstance(content,dict) else json.loads(str(content).strip().strip("`")); code=str(data.get("asset_code") or "").upper().replace(" ","")
        return (code,float(data.get("confidence",0))) if PATTERN.fullmatch(code) else (None,0.0)
