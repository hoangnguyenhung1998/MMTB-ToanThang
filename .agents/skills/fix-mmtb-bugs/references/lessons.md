# Bài học đã xác minh

## Điều chuyển hồi tố — 09/10/2026

Trigger: tạo October tại A rồi nhập A→B ngày05/10, hiệu lực25/09. BCH từ ngày hiệu lực thuộc B đến transfer/return/gap kế tiếp; giờ/ảnh độc lập BCH.

Nguyên nhân giữ lỗi: propagation đã gọi repair nhưng PR67 từ chối72 shadow0/NULL và40 cặp có captures chưa đủ paired-job proof. Generator giữ occupancy, sync bỏ qua duplicate. Writer lịch sử tạo dòng sai UNKNOWN.

Sửa bằng planner chung ownership-specific: canonical proof xác định rich survivor, giữ hour bundle/NULL/0, union evidence hợp lệ, relink/carry/delete/audit atomic. Không nới guard cho nhật trình độc lập/protected. Không coi0/NULL tương đương trong mọi nghiệp vụ.

Regression: kỳ tạo trước transfer/revision/global repair; rich ở A/B; complementary evidence; giờ thật xung đột; return/gap/A→B→C; generator/sync/replay; stale/rollback/atomicity/MySQL concurrency.

Local acceptance báo129 wrong ownership/excess→0, Validator1171/108→0/0, giữ322 NULL machine-days. PR68 merge435cb64; user xác nhận sửa dứt điểm trên hosting09/10/2026. Bytes ảnh restore chưa kiểm chứng; September còn canonical ambiguity độc lập.

## STALE_PREVIEW — 09/10/2026

October live bị STALE_PREVIEW; preview sau về0 nhưng chưa chứng minh tác vụ nào đã xử lý hoặc trường snapshot nào đổi. Không khẳng định cron/sync là nguyên nhân chỉ vì chạy mỗi15 phút. Preview0 không chứng minh delete thành công. Đối chiếu run/audit và case thực tế; tách quan sát/suy luận/unknown. Nếu lặp stale, kiểm tra thay đổi nghiệp vụ/protection thật, SQL order và metadata kỹ thuật; không tắt guard hoặc bắt retry vô hạn.
