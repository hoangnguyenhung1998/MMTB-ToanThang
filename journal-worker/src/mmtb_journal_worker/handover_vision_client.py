from __future__ import annotations

import base64
import json
from pathlib import Path
from typing import Any
import httpx
from pydantic import ValidationError
from .handover_models import MachineHandoverExtraction
from .vision_client import VisionError


class MachineHandoverVisionClient:
    def __init__(self, api_base_url: str, api_key: str, model: str, timeout_seconds: int, client: httpx.Client | None = None):
        self.api_base_url=api_base_url.rstrip("/"); self.api_key=api_key; self.model=model; self.timeout_seconds=timeout_seconds; self.client=client

    def extract(self, image_path: Path) -> MachineHandoverExtraction:
        payload=self._payload(image_path); headers={"Authorization":f"Bearer {self.api_key}","Content-Type":"application/json"}
        try:
            if self.client: response=self.client.post(f"{self.api_base_url}/chat/completions",headers=headers,json=payload,timeout=self.timeout_seconds)
            else:
                with httpx.Client(timeout=self.timeout_seconds) as client: response=client.post(f"{self.api_base_url}/chat/completions",headers=headers,json=payload)
            response.raise_for_status(); content=response.json()["choices"][0]["message"]["content"]
            return MachineHandoverExtraction.model_validate(self._json(content))
        except httpx.HTTPStatusError as exc: raise VisionError(f"Vision API returned {exc.response.status_code}: {exc.response.text[:1000]}",exc.response.status_code==429 or exc.response.status_code>=500) from exc
        except (httpx.HTTPError,OSError) as exc: raise VisionError(f"Vision API request failed: {exc}",True) from exc
        except (ValidationError,KeyError,ValueError,TypeError,json.JSONDecodeError) as exc: raise VisionError(f"Vision response is not valid handover JSON: {exc}",False) from exc

    def _payload(self,image_path:Path)->dict[str,Any]:
        mime={".png":"image/png",".webp":"image/webp",".tif":"image/tiff",".tiff":"image/tiff"}.get(image_path.suffix.lower(),"image/jpeg")
        encoded=base64.b64encode(image_path.read_bytes()).decode("ascii")
        instruction=("Bạn là OCR biên bản bàn giao máy công trình viết tay. Chỉ đọc nội dung nhìn thấy, không suy đoán. "
            "Trích mã thiết bị/mã tài sản đúng nguyên văn (không giả định tiền tố VT/T và không suy ra công ty từ mã), ngày bàn giao YYYY-MM-DD, tên dự án, BCH/đơn vị nhận, loại máy, model, số giờ, GPS, người giao nhận và lỗi kỹ thuật. "
            "Không lấy giờ trên watermark làm giờ bàn giao. Không tự gán tài xế. Nếu chữ không chắc thêm AMBIGUOUS_<FIELD>. "
            "Trả duy nhất JSON: {asset_code,handover_date,project_text,command_center_text,machine_type,model_name,meter_hours,gps_status,handover_people,technical_findings,confidence,raw_text,review_flags}.")
        return {"model":self.model,"messages":[{"role":"user","content":[{"type":"text","text":instruction},{"type":"image_url","image_url":{"url":f"data:{mime};base64,{encoded}"}}]}],"temperature":0,"response_format":{"type":"json_object"}}

    @staticmethod
    def _json(content:Any)->dict[str,Any]:
        if isinstance(content,dict): return content
        text=str(content).strip()
        if text.startswith("```"): text=text.strip("`"); text=text[4:].strip() if text.lower().startswith("json") else text
        return json.loads(text)
