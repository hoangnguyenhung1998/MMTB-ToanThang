# RUNBOOK — PHASE MANAGEMENT

## Mục đích

Đảm bảo dự án có thể tiếp tục chính xác khi mất chat, đổi máy, đổi Codex session hoặc công việc bị gián đoạn.

## 1. Bắt đầu phiên

1. Đọc `AGENTS.md`.
2. Đọc `docs/PROJECT_STATE.md`.
3. Đọc `docs/PHASE_INDEX.md`.
4. Xác định Current Phase.
5. Đọc file Phase hiện tại.
6. Đọc Dependencies/Related cần thiết.
7. Đọc runbook liên quan nếu có.
8. Kiểm tra Git: branch, status, commit gần nhất, thay đổi chưa commit.
9. Xác nhận NEXT ACTION trước khi sửa code.

## 2. Trong khi thực hiện

Cập nhật `PROJECT_STATE.md` sau milestone quan trọng, bao gồm:

- Step PASS/FAIL;
- phát hiện nguyên nhân lỗi;
- thay đổi hướng xử lý;
- quyết định kỹ thuật ảnh hưởng bước tiếp theo;
- trước deploy;
- sau deploy;
- trước khi đổi máy hoặc kết thúc phiên.

Không chờ hoàn thành toàn bộ Phase mới cập nhật checkpoint.

## 3. NEXT ACTION

NEXT ACTION phải đủ để session mới thực hiện ngay và trả lời được:

1. Làm gì?
2. Làm ở đâu?
3. Kiểm tra gì?
4. PASS/FAIL được xác định thế nào?
5. PASS xong làm gì?
6. Hiện tại chưa được làm gì?

Không dùng mô tả mơ hồ như `tiếp tục test`.

## 4. Kết thúc phiên

Cập nhật `PROJECT_STATE.md`:

- Step vừa hoàn thành;
- Step đang dừng;
- NEXT ACTION;
- test gần nhất;
- blockers/findings;
- branch;
- commit gần nhất;
- working tree;
- thay đổi chưa push nếu có.

Cập nhật file Phase với checklist, test, findings và quyết định quan trọng.

## 5. Hoàn thành Phase

Chỉ đánh dấu `COMPLETED` khi:

- scope hoàn thành;
- test bắt buộc PASS;
- blocker thuộc scope đã xử lý;
- file Phase cập nhật;
- `PHASE_INDEX.md` cập nhật;
- `PROJECT_STATE.md` chuyển sang trạng thái tiếp theo;
- Git phù hợp với tài liệu;
- production verification hoàn tất nếu Phase yêu cầu deploy.

## 6. Tạo Phase mới

Chỉ tạo Phase mới khi scope đủ độc lập, Phase hiện tại hoàn thành, vấn đề nằm ngoài scope, cần tách rủi ro, hoặc người dùng yêu cầu.

Nếu vấn đề vẫn thuộc scope hiện tại, ưu tiên tiếp tục Phase hiện tại.

## 7. Context tối thiểu

Không đọc toàn bộ lịch sử Phase.

Ưu tiên:

1. Current Phase.
2. Direct Dependencies.
3. Related Phase có liên quan kỹ thuật.
4. Runbook liên quan.
5. Phase cũ hơn chỉ khi cần điều tra cụ thể.

## 8. Xử lý mất chat/context

Không yêu cầu người dùng kể lại toàn bộ ngay lập tức.

Khôi phục theo thứ tự:

`AGENTS.md → PROJECT_STATE.md → PHASE_INDEX.md → Current Phase → Dependencies/Related → Git/code/test`

Chỉ hỏi người dùng khi repository vẫn không đủ thông tin để quyết định an toàn.

## 9. Nguồn sự thật

Ưu tiên:

`Verified repository state > documentation > chat history > assumption`
