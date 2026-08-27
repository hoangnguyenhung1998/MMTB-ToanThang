from __future__ import annotations

import base64
import json
from pathlib import Path
from typing import Any
import httpx
from pydantic import ValidationError
from .intake_models import MachineIntakeExtraction
from .vision_client import VisionError


class MachineIntakeVisionClient:
    def __init__(self, api_base_url: str, api_key: str, model: str, timeout_seconds: int, client: httpx.Client | None = None):
        self.api_base_url=api_base_url.rstrip("/"); self.api_key=api_key; self.model=model; self.timeout_seconds=timeout_seconds; self.client=client

    def extract(self, image_path: Path, document_type: str) -> MachineIntakeExtraction:
        payload=self._payload(image_path,document_type); headers={"Authorization":f"Bearer {self.api_key}","Content-Type":"application/json"}
        try:
            if self.client: response=self.client.post(f"{self.api_base_url}/chat/completions",headers=headers,json=payload,timeout=self.timeout_seconds)
            else:
                with httpx.Client(timeout=self.timeout_seconds) as client: response=client.post(f"{self.api_base_url}/chat/completions",headers=headers,json=payload)
            response.raise_for_status(); content=response.json()["choices"][0]["message"]["content"]
            return MachineIntakeExtraction.model_validate(self._json(content))
        except httpx.HTTPStatusError as exc: raise VisionError(f"Vision API returned {exc.response.status_code}: {exc.response.text[:1000]}",exc.response.status_code==429 or exc.response.status_code>=500) from exc
        except (httpx.HTTPError,OSError) as exc: raise VisionError(f"Vision API request failed: {exc}",True) from exc
        except (ValidationError,KeyError,ValueError,TypeError,json.JSONDecodeError) as exc: raise VisionError(f"Vision response is not valid machine intake JSON: {exc}",False) from exc

    def _payload(self,image_path:Path,document_type:str)->dict[str,Any]:
        mime={".png":"image/png",".webp":"image/webp",".tif":"image/tiff",".tiff":"image/tiff"}.get(image_path.suffix.lower(),"image/jpeg")
        encoded=base64.b64encode(image_path.read_bytes()).decode("ascii")
        instruction=("Bạn là OCR kiểm soát hồ sơ máy công trình. Loại ảnh: "+document_type+". Chỉ đọc ký tự thực sự nhìn thấy, tuyệt đối không suy đoán. "
            "Trích công ty VINCONS/VINALPHA nếu có, số khung, số máy/động cơ, loại máy, model và năm sản xuất. "
            "Số khung và số máy phải giữ đúng thứ tự ký tự. Đặc biệt kiểm tra cặp dễ nhầm 0/O, 1/I, 5/S, 2/Z, 8/B. "
            "Nếu không chắc, giữ giá trị đọc tốt nhất nhưng thêm cờ AMBIGUOUS_<FIELD>_<POSITION> vào review_flags. Không dùng mã tài sản vì hồ sơ chưa được cấp mã. "
            "confidence phản ánh độ chắc chắn 0..1; raw_text giữ nguyên văn chính. Trả duy nhất JSON: "
            "{company,chassis_no,engine_no,machine_type,model_name,manufacture_year,confidence,raw_text,review_flags}.")
        return {"model":self.model,"messages":[{"role":"user","content":[{"type":"text","text":instruction},{"type":"image_url","image_url":{"url":f"data:{mime};base64,{encoded}"}}]}],"temperature":0,"response_format":{"type":"json_object"}}

    @staticmethod
    def _json(content:Any)->dict[str,Any]:
        if isinstance(content,dict): return content
        text=str(content).strip()
        if text.startswith("```"): text=text.strip("`"); text=text[4:].strip() if text.lower().startswith("json") else text
        return json.loads(text)
