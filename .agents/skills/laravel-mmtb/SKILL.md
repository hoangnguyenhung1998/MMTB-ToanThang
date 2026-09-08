---
name: laravel-mmtb
description: Dùng khi sửa Laravel MMTB, quản lý máy, API ứng dụng, model/migration, giao diện web, authentication hoặc Laravel tests.
---

# Skill: Laravel MMTB

## Khi nào dùng

Dùng Skill này cho:

- Quản lý máy móc thiết bị.
- Machine events và chuyển trạng thái.
- Bàn giao / kích hoạt / trả máy.
- Import/export.
- Batch operations.
- Giao diện Laravel.
- API Laravel.
- Authentication/authorization.
- Model và migration.
- Laravel feature/unit tests.
- Control plane của automation nằm trong Laravel.

Không cần đọc Skill này cho thay đổi Python worker hoặc Collector độc lập nếu Laravel không bị ảnh hưởng.

---

## Định danh máy

Các trường định danh như `asset_code`, `chassis_no` là dữ liệu nghiệp vụ quan trọng.

Không tự:

- Gộp hai máy.
- Đổi identity.
- Thay đổi uniqueness.
- Chuẩn hóa mã theo cách có thể làm hai máy khác nhau thành một.

Mọi thay đổi identity phải bảo toàn record và reference hiện có.

---

## Vòng đời máy

Các trạng thái nghiệp vụ đã dùng gồm:

- `WAIT_HANDOVER`
- `HANDED_OVER`
- `ACTIVE`
- `RETURNED`

Giữ nguyên ý nghĩa chuyển trạng thái nếu nhiệm vụ hiện tại không chủ động thay đổi.

Không bỏ qua event/history chỉ để đơn giản hóa UI.

Nếu `machine_events` đang là lịch sử chuẩn, trạng thái hiển thị phải nhất quán với event history.

---

## API

Với API hiện có:

- Giữ tương thích request/response nếu không được yêu cầu đổi contract.
- Giữ authentication và throttling.
- Giữ idempotency ở những nơi đang yêu cầu.
- Không nới validation chỉ để test PASS.
- Thêm/cập nhật test khi behavior API thay đổi.

Nếu API liên quan ingestion, đọc thêm Skill Collector/Worker phù hợp.

---

## Database

Ưu tiên:

- migration additive;
- field mới nullable khi cần rollout an toàn;
- index rõ ràng khi cần lookup;
- backward compatibility.

Không sửa migration có khả năng đã chạy production chỉ để thay đổi behavior.

Luôn tính đến dữ liệu production hiện có.

---

## operation

Với batch operation:
- không để một record lỗi làm trạng thái các record khác trở nên không xác định;
- phải xác định rõ atomic/partial-success semantics hiện tại trước khi thay đổi;
- không thay đổi semantics batch chỉ để đơn giản hóa implementation.

---

## UI

Khi sửa giao diện quản lý:

- Giữ workflow vận hành hiện tại nếu không có yêu cầu redesign.
- Hiển thị rõ success/error.
- Không giấu destructive action.
- Giữ filter và khả năng xem dữ liệu.
- Không bỏ khả năng sửa thủ công mà nghiệp vụ đối chiếu hiện tại cần.

Với action bất đồng bộ, cần trạng thái loading/progress/result rõ ràng.

---

## Test

Ưu tiên targeted Laravel tests:

```bash
php artisan test --filter=<RelevantTest>
```

Mở rộng suite khi shared infrastructure bị thay đổi.

Không sửa expected value chỉ để làm test xanh nếu business behavior thực tế không thay đổi.

---

## Hoàn thành khi

- Đã kiểm tra route/controller/service/model liên quan.
- Business behavior được bảo toàn.
- Validation đúng.
- Test liên quan PASS.
- Đã báo migration impact.
- Đã nêu manual verification.
- Không có refactor Laravel ngoài phạm vi.
