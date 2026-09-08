---
name: zalo-collector
description: Dùng khi sửa Zalo Collector, zca-js/OpenClaw Zalo integration, session/tài khoản, chuyển tài khoản, nhóm allowlist, ingestion ảnh/tin nhắn hoặc Collector tests/runtime.
---

# Skill: Zalo Collector

## Phạm vi

Dùng cho:

- Zalo Collector.
- zca-js.
- Login/session Zalo.
- Nhiều profile tài khoản.
- Chuyển tài khoản.
- Lấy danh sách nhóm.
- Group allowlist.
- Nhận ảnh/tin nhắn.
- Retry.
- Runtime config.
- Collector tests.

---

## Kiến trúc

Luồng tổng quát:

Zalo → Collector → Laravel ingestion API → lưu trữ / OCR / automation.

Laravel là hệ thống trung tâm.

Collector nên tập trung vào:

- thu thập;
- lọc đúng nguồn;
- gửi dữ liệu tin cậy;
- chống mất dữ liệu;
- runtime resilience.

---

## An toàn session

Zalo session là credential nhạy cảm.

Không:

- commit session file;
- log session secret;
- copy session vào tài liệu;
- expose cookie/token.

Không để việc chuyển tài khoản ghi đè session của tài khoản khác.

Mỗi profile tài khoản phải được coi là runtime identity riêng.

---

## Nhóm và allowlist

Chỉ thu thập từ các nhóm đã được cho phép.

Khi chuyển tài khoản:

- danh sách nhóm phải thuộc đúng tài khoản;
- không hiển thị stale group từ account trước;
- giữ cấu hình nhóm theo account nếu thiết kế hiện tại yêu cầu;
- thông báo rõ switch thành công/thất bại.

Không tự mở rộng phạm vi nhóm thu thập.

---

## Tính toàn vẹn ingestion

Giữ các lớp chống duplicate/idempotency hiện có.

Các cơ chế đã sử dụng có thể gồm:

- `messageId` idempotency;
- SHA-256 duplicate detection;
- hash mismatch validation;
- replay-safe API behavior.

Không bỏ một lớp bảo vệ chỉ vì đang có lớp khác nếu chưa xác minh kiến trúc.

Duplicate không được tạo downstream work trùng.

---

## Độ tin cậy

Khi Laravel/API/network lỗi tạm thời, Collector không được làm mất pending work hợp lệ.

Retry phải:

- không loop quá nhanh;
- không gây duplicate side effect;
- giữ pending queue khi phù hợp;
- log đủ thông tin xử lý nhưng không chứa secret.

---

## Laptop runtime

Laptop 24/24 là runtime node.

Không dùng nó để thử thay đổi Collector rủi ro nếu có thể test trước trên PC development.

Luồng ưu tiên:

development local → Collector tests → integration test → GitHub → update laptop → restart đúng process → kiểm tra log/ingestion.

---

## UI quản lý tài khoản

Nếu Laravel quản lý account/group:

- hiển thị rõ account đang active;
- có loading/progress khi switch;
- success/error rõ ràng;
- refresh group sau khi switch;
- chống submit lặp;
- giữ auditability của operational command nếu hệ thống hiện có hỗ trợ.

---

## Test

Ưu tiên:

- authentication;
- store;
- replay;
- duplicate;
- hash mismatch;
- account switch;
- refresh group;
- allowlist;
- API unavailable rồi phục hồi;
- retry không tạo duplicate downstream.

---

## Hoàn thành khi

- Session vẫn an toàn.
- Account isolation đúng.
- Group scope đúng.
- Duplicate protection còn nguyên.
- Retry an toàn.
- Đã báo runtime restart/update impact.
- Collector tests liên quan PASS.
