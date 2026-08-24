from __future__ import annotations

import base64
import json
from pathlib import Path
from typing import Any

import httpx
from pydantic import ValidationError

from .models import JournalExtraction


class VisionError(RuntimeError):
    def __init__(self, message: str, retryable: bool = True):
        super().__init__(message)
        self.retryable = retryable


class JournalVisionClient:
    def __init__(
        self,
        api_base_url: str,
        api_key: str,
        model: str,
        timeout_seconds: int,
        client: httpx.Client | None = None,
    ):
        self.api_base_url = api_base_url.rstrip("/")
        self.api_key = api_key
        self.model = model
        self.timeout_seconds = timeout_seconds
        self.client = client

    def extract(self, image_path: Path, machine_codes: list[str]) -> JournalExtraction:
        payload = self._build_payload(image_path, machine_codes)
        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json",
        }
        try:
            if self.client is not None:
                response = self.client.post(
                    f"{self.api_base_url}/chat/completions",
                    headers=headers,
                    json=payload,
                    timeout=self.timeout_seconds,
                )
            else:
                with httpx.Client(timeout=self.timeout_seconds) as client:
                    response = client.post(
                        f"{self.api_base_url}/chat/completions",
                        headers=headers,
                        json=payload,
                    )
            response.raise_for_status()
            data = response.json()
            content = data["choices"][0]["message"]["content"]
            return JournalExtraction.model_validate(self._parse_json_content(content))
        except httpx.HTTPStatusError as exc:
            retryable = exc.response.status_code == 429 or exc.response.status_code >= 500
            raise VisionError(
                f"Vision API returned {exc.response.status_code}: {exc.response.text[:1000]}",
                retryable=retryable,
            ) from exc
        except (httpx.HTTPError, OSError) as exc:
            raise VisionError(f"Vision API request failed: {exc}", retryable=True) from exc
        except (KeyError, ValueError, TypeError, ValidationError, json.JSONDecodeError) as exc:
            raise VisionError(f"Vision response is not valid journal JSON: {exc}", retryable=True) from exc

    def _build_payload(self, image_path: Path, machine_codes: list[str]) -> dict[str, Any]:
        mime_type = {
            ".png": "image/png",
            ".webp": "image/webp",
            ".bmp": "image/bmp",
            ".tif": "image/tiff",
            ".tiff": "image/tiff",
        }.get(image_path.suffix.lower(), "image/jpeg")
        encoded = base64.b64encode(image_path.read_bytes()).decode("ascii")
        catalog = ", ".join(machine_codes)
        instruction = (
            "Bạn là OCR vision chuyên đọc ảnh NHẬT TRÌNH HOẠT ĐỘNG THIẾT BỊ thi công viết tay. "
            "Chỉ đọc dữ liệu nhìn thấy, tuyệt đối không bịa hoặc điền theo suy đoán. "
            "Mỗi dòng có dữ liệu trên bảng phải tạo đúng một phần tử rows theo thứ tự từ trên xuống. "
            "Đọc mã thiết bị, ngày, toàn bộ khoảng giờ bắt đầu/kết thúc, nội dung công việc và vị trí. "
            "Giữ nguyên thứ tự dòng từ trên xuống. Nếu ô NGÀY của dòng trống thì trả work_date=null, "
            "không tự sao chép và không đoán; tầng quy tắc phía sau sẽ kế thừa ngày trong cùng block. "
            "Ngày nhìn thấy có thể trả YYYY-MM-DD hoặc DD/MM; giờ dùng HH:MM:SS. "
            "Không phân loại giờ hành chính/tăng ca và không tự cộng tổng theo nghiệp vụ. "
            "Bỏ từ ca Sáng/Chiều/Tối khỏi work_content nhưng giữ câu OCR gốc trong raw_data. "
            "Các trạng thái mưa, nghỉ, chờ việc, chờ dầu, máy hỏng, bảo dưỡng hoặc trả máy vẫn phải "
            "đưa vào work_content; đồng thời trả vào error_explanation. "
            "Nếu trường nào không đọc được hãy để null. work_content chỉ lấy từ cột NỘI DUNG CÔNG VIỆC; "
            "work_location chỉ lấy từ vị trí/địa điểm. "
            "confidence của từng dòng phản ánh độ chắc chắn 0..1. raw_text giữ gần nguyên văn để hậu kiểm. "
            "raw_data của từng dòng bắt buộc là JSON object, ví dụ {\"text\": \"nội dung nguyên dòng\"}; "
            "không được trả raw_data dưới dạng chuỗi. "
            "asset_code chỉ chọn chính xác từ danh mục sau nếu nhìn thấy khớp; nếu không chắc để null: "
            f"{catalog}. "
            "Trả duy nhất JSON hợp lệ, không markdown, theo schema: "
            "{asset_code, confidence, raw_text, rows:[{row_number, work_date, start_time, end_time, "
            "total_minutes, work_content, error_explanation, quantity, unit, work_location, "
            "operator_name, confidence, raw_data}]}."
        )
        return {
            "model": self.model,
            "messages": [{
                "role": "user",
                "content": [
                    {"type": "text", "text": instruction},
                    {
                        "type": "image_url",
                        "image_url": {"url": f"data:{mime_type};base64,{encoded}"},
                    },
                ],
            }],
            "temperature": 0,
            "response_format": {"type": "json_object"},
        }

    @staticmethod
    def _parse_json_content(content: Any) -> dict[str, Any]:
        if isinstance(content, dict):
            return content
        text = str(content).strip()
        if text.startswith("```"):
            text = text.strip("`")
            if text.lower().startswith("json"):
                text = text[4:].strip()
        return json.loads(text)
