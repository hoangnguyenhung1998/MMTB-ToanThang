---
name: fix-mmtb-bugs
description: Sửa lỗi MMTB-ToanThang theo tình huống nghiệp vụ thực tế, tìm nguyên nhân gốc, sửa dữ liệu cũ và ngăn tái phát. Dùng khi báo lỗi MMTB, viết prompt Codex sửa lỗi, review patch, kiểm chứng hoặc triển khai bản sửa; tích lũy bài học đã xác minh.
---

# Sửa lỗi MMTB

## Nguyên tắc

Bám tình huống và kết quả người dùng muốn đạt. Không bắt đầu bằng guard hoặc checklist dài trước khi hiểu lỗi. Hướng dẫn mới nhất của người dùng quyết định requirement; code và docs repo là bằng chứng implementation. Không dùng guard cũ để phủ nhận requirement mới.

Không hỏi lại thông tin đã có; đọc nguồn sẵn có trước. Tách dữ liệu độc lập: sửa quan hệ không mặc định tính lại nội dung. Không thay dữ liệu/protection để đạt số liệu đẹp. Tiếp tục các bước đã được ủy quyền; skill không cấp quyền mới hoặc yêu cầu xin lại quyền đã có.

## 1. Context và acceptance

Đọc AGENTS.md, docs/PROJECT_STATE.md, docs/PHASE_INDEX.md, phase/runbook hiện tại và skill subsystem liên quan. Kiểm tra Git status, branch, HEAD và môi trường thực tế; bảo toàn local/untracked, không tự reset/clean/stash. Đọc [project-context.md](references/project-context.md) khi cần đường dẫn/lệnh; xác minh trạng thái động trước mutation.

Chốt ngắn tối đa năm dòng:
- Trigger: thứ tự thao tác, ngày hiệu lực, thời điểm nhập nếu liên quan.
- Actual: hành vi/dữ liệu sai cụ thể.
- Expected: kết quả đúng theo user.
- Invariants: dữ liệu phải giữ, protection và quyền.
- Acceptance: case thực tế chứng minh hết lỗi và không tái phát.

Nếu viết prompt Codex, tạo prompt hành động tự chứa theo workflow này, kèm workspace, source of truth, quyền hiện có và report trực tiếp trong chat. Không bắt user tìm report riêng.

## 2. Tái hiện và tìm nguyên nhân

Tạo regression đúng thứ tự sự kiện, ghi FAIL trước sửa nếu khả thi. Truy vết entry point, propagation, generator, sync, worker và nguồn nghiệp vụ liên quan. Phân biệt nguyên nhân tạo dữ liệu sai với nguyên nhân giữ dữ liệu sai; writer lịch sử chưa rõ thì ghi UNKNOWN.

Chọn sửa nhỏ nhất đủ bao phủ lỗi. Dùng planner/service chung cho dữ liệu cũ và đường ghi mới. Xác định dedupe scope theo kỳ/máy/ngày/segment và survivor bằng nguồn nghiệp vụ; không chọn ID/BCH hiện tại mù quáng. Không áp bài học riêng cho mọi loại duplicate.

## 3. Sửa và kiểm chứng

- Sửa dữ liệu cũ và ngăn generator/sync/replay tạo lại lỗi; không dựng hệ thống song song nếu kiến trúc hiện có xử lý được.
- Với relink/dedupe, bảo toàn nguồn, chuyển references cần thiết, lock/revalidate và relink/delete/audit atomically; giữ retry/idempotence, quyền và protection.
- Với xung đột nội dung thật thiếu nguồn quyết định, báo máy/ngày/trường và proof thiếu; không cộng/ghi đè tùy ý.
- Chạy targeted regression, rồi subsystem/full theo rủi ro. Tests PASS không thay cho acceptance nghiệp vụ.
- Destructive tests dùng fixtures riêng. Repair lớn/locking dùng MySQL bản sao cách ly và concurrency bằng process độc lập; không gọi tuần tự là concurrency PASS.
- So sánh trước/sau, survivor payload, NULL/0, ảnh/OCR/GPS references, FK, protected và lần chạy thứ hai. Phân biệt metadata với bytes file.
- Đọc [lessons.md](references/lessons.md) khi liên quan điều chuyển hồi tố hoặc stale-preview.

## 4. Review và release

Review đúng patch/version/hash; loại raw JSON, backups, secrets/runtime khỏi Git. Nêu rõ static review và tests thực sự do ai chạy. Nếu commit/push/PR/merge đã được ủy quyền, thực hiện đến hết và kiểm tra exact head/CI. Không có access máy user thì đưa khối lệnh đủ và điều kiện dừng, không giả vờ đã chạy.

Theo runbook triển khai hiện hành; bảo toàn cấu hình hosting riêng. Chỉ migrate khi cần migration mới; không restart Collector/OCR nếu không cần. Phân biệt scheduler registration, cron configuration và execution.

Dùng fresh preview và đối chiếu action/reason với phạm vi đã cho phép, không suy live counters từ backup. Một fresh-preview retry hợp lý khi stale; nếu lặp, điều tra diagnostics thay vì bắt retry mãi. Không kết luận cron là nguyên nhân khi chưa có evidence.

Kiểm tra case ban đầu trên hosting: đúng ownership/nội dung, không dư, giờ/ảnh đủ. Preview0 không chứng minh lịch sử apply hoặc mọi dữ liệu sạch. Ghi user-confirmed acceptance riêng.

## 5. Report và nâng cấp skill

Trả ngay trong chat: nguyên nhân, thay đổi, case acceptance và tests/evidence thực tế, unresolved/giới hạn, release, một bước tiếp theo nếu còn. Phân biệt LOCAL PASS, REVIEWED, DEPLOYED, LIVE VERIFIED. Không nói xong chỉ vì tests PASS.

Sau mỗi lần sử dụng, xem user phải nhắc lại gì, assumption nào sai, bước nào thừa và regression nào thiếu. Khi có bài học mới đã xác minh:
1. Chỉ thêm nguyên tắc tái sử dụng vào SKILL.md.
2. Thêm case ngắn trong lessons.md: ngày, trigger, nguyên nhân có evidence, nguyên tắc, regression, kết quả và giới hạn.
3. Gộp trùng; không lưu secrets/raw production logs/dữ liệu cá nhân.
4. Giữ checkpoint/release chi tiết trong docs repo, không biến skill thành changelog.
5. Validate, review diff và đưa vào nhánh sửa project. Publish theo quyền hiện có; nếu chưa được phép, giữ diff local và báo rõ. Bản .agents/skills/fix-mmtb-bugs là nguồn chuẩn project; không tự cập nhật bản ChatGPT Work riêng hoặc tự push chỉ vì đã dùng skill.
