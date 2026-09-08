---
name: automation-workers
description: Dùng khi sửa OCR/reconciliation/health/automation workers, polling, queue/job, operational command, retry/failure hoặc các tiến trình Python/background chạy lâu dài.
---

# Skill: Automation Workers

## Phạm vi

Dùng cho:

- OCR workers.
- Reconciliation workers.
- Health/operational agents.
- Polling loop.
- Claim job.
- Retry.
- Worker command/control.
- Timeout.
- Queue/pending jobs.
- Worker log và operational state.

---

## Runtime

Workers có thể chạy liên tục trên laptop 24/24 và kết nối Laravel production.

Mọi thay đổi worker phải coi là runtime-sensitive.

Ưu tiên development/test trước khi cập nhật laptop runtime.

---

## An toàn job

Giữ toàn vẹn job lifecycle.

Không tạo behavior có thể:

- claim cùng job đồng thời;
- làm mất queue;
- đánh dấu job lỗi thành completed;
- xử lý lại completed job khi không có replay semantics;
- để job kẹt vĩnh viễn ở trạng thái trung gian.

Nếu hệ thống có claim limit, lookback, poll interval hoặc timeout, giữ nguyên operational intent nếu nhiệm vụ không yêu cầu thay đổi.

---

## Retry

Retry phải có giới hạn và không tạo side effect trùng.

Với lỗi tạm thời như:

- HTTP 500;
- connection reset;
- connection refused;
- timeout;

ưu tiên recoverable retry theo kiến trúc hiện tại.

Không:

- infinite retry quá nhanh;
- tạo duplicate;
- nuốt lỗi persistent mà không tạo trạng thái lỗi rõ ràng.

---

## Trạng thái kết quả

Nếu hệ thống phân biệt:

- `COMPLETED`
- `WARNING`
- `EXCEPTION`
- `FAILED`

phải giữ đúng ý nghĩa.

Không đổi exception thành success chỉ để pipeline xanh.

WARNING phải đủ thông tin xử lý nhưng không nhất thiết block pipeline nếu business rule không yêu cầu.

---

## Logging

Log nên có:

- timestamp;
- job ID;
- machine/reference ID nếu có;
- lỗi ngắn gọn;
- retry/final state.

Không log secret/session.

Không tạo log quá nhiễu ở mỗi poll nếu không có giá trị vận hành.

---

## Hiệu năng và an toàn máy

Laptop runtime từng xử lý workload OCR lớn.

Không tự tăng concurrency hoặc CPU fan-out không kiểm soát.

Khi sửa batch/concurrency:

- đánh giá CPU/RAM;
- giữ giới hạn an toàn;
- dùng worker count có giới hạn;
- báo rõ operational impact.

Không mặc định nhanh hơn là tốt hơn cho máy chạy 24/24.

---

## Phối hợp với Laravel

Nếu payload/API contract giữa worker và Laravel thay đổi:

- sửa hai phía đồng bộ;
- giữ compatibility trong rollout nếu có thể;
- xác định thứ tự deploy;
- thêm integration test khi hợp lý.

---

## Test

Ưu tiên các case:

- job thành công;
- retryable failure;
- terminal failure;
- malformed payload;
- timeout;
- duplicate/replay;
- worker restart khi còn pending;
- Laravel API unavailable rồi phục hồi.

---

## Hoàn thành khi

- Job lifecycle an toàn.
- Retry có giới hạn.
- Không tạo duplicate side effect.
- Đã xem xét resource impact.
- Log hữu ích và không lộ secret.
- Laravel compatibility đúng.
- Đã báo restart/update instruction.
- Worker tests liên quan PASS.
