# Bài học đã xác minh

## Điều chuyển hồi tố — 09/10/2026

Trigger: tạo October tại A rồi nhập A→B ngày05/10, hiệu lực25/09. BCH từ ngày hiệu lực thuộc B đến transfer/return/gap kế tiếp; giờ/ảnh độc lập BCH.

Nguyên nhân giữ lỗi: propagation đã gọi repair nhưng PR67 từ chối72 shadow0/NULL và40 cặp có captures chưa đủ paired-job proof. Generator giữ occupancy, sync bỏ qua duplicate. Writer lịch sử tạo dòng sai UNKNOWN.

Sửa bằng planner chung ownership-specific: canonical proof xác định rich survivor, giữ hour bundle/NULL/0, union evidence hợp lệ, relink/carry/delete/audit atomic. Không nới guard cho nhật trình độc lập/protected. Không coi0/NULL tương đương trong mọi nghiệp vụ.

Regression: kỳ tạo trước transfer/revision/global repair; rich ở A/B; complementary evidence; giờ thật xung đột; return/gap/A→B→C; generator/sync/replay; stale/rollback/atomicity/MySQL concurrency.

Local acceptance báo129 wrong ownership/excess→0, Validator1171/108→0/0, giữ322 NULL machine-days. PR68 merge435cb64; user xác nhận sửa dứt điểm trên hosting09/10/2026. Bytes ảnh restore chưa kiểm chứng; September còn canonical ambiguity độc lập.

## STALE_PREVIEW — 09/10/2026

October live bị STALE_PREVIEW; preview sau về0 nhưng chưa chứng minh tác vụ nào đã xử lý hoặc trường snapshot nào đổi. Không khẳng định cron/sync là nguyên nhân chỉ vì chạy mỗi15 phút. Preview0 không chứng minh delete thành công. Đối chiếu run/audit và case thực tế; tách quan sát/suy luận/unknown. Nếu lặp stale, kiểm tra thay đổi nghiệp vụ/protection thật, SQL order và metadata kỹ thuật; không tắt guard hoặc bắt retry vô hạn.


## Timeout recovery ảnh chờ — 09/10/2026

Trigger: HTTP recovery chạy toàn bộ mutation/pairing/sync cả kỳ trước redirect. Vị trí Collection.php trong fatal chỉ là điểm bị ngắt. Fixture91 pending(89eligible/2manual)+protected và600 dòng unrelated đo sync3,23/5,54s, chậm nhất tại local; bottleneck cụ thể trên hosting chưa đo.

Nguyên tắc: POST chỉ persist phạm vi/actor/UUID; scheduler hiện có thực hiện bounded units. Outbox pairing/sync (kèm exact eligible period IDs), mutation và counters commit cùng transaction; kỳ khóa sau commit phải giữ nghĩa vụ và báo lỗi, không query lại rồi bỏ qua để báo hoàn tất. Lease/attempt phải bền vững trước business transaction để hard-crash không lặp vô hạn. Tiến độ đọc persisted state, OCR retry không đồng nghĩa thành công. COMPLETED của luồng cũ cần proof downstream và repair riêng, không reset hàng loạt hay re-OCR.

Regression đã xác minh: fixture/manual/protected, light/private POST/progress, state/filter changes, fault rollback/retry, crash trước/sau commit và true two-process MySQL dispatch/claim; evidence/counters không trùng. Preview pairing phải xét evidence count cùng timestamp để phát hiện thêm ảnh trong cùng giây; signature actual/expected chứng minh sync thiếu. Giữ observer AUTO_APPROVED hiện có; hash nguồn/protected mới là invariant phù hợp. LOCAL ONLY, chưa deploy/live verify.


## Review evidence move và reason — 23/10/2026

Hai regression FAIL trước sửa: outbox có pairing case cũ nhưng thiếu sync máy/ngày cũ; DOWNSTREAM chỉ kiểm tra analyse có row nên bỏ qua reason mà recovery lọc ở caller. Lưu scope cũ từ membership trước mutation, cả scope mới/cũ và period IDs cùng transaction; pointer trên job có thể thiếu hoặc stale. Diagnostic/CLI gate/worker cần cùng predicate reason hiện tại và revalidate sau dispatch, không nhầm proof reason với filter reason.

SQLite/MySQL16tests/190assertions PASS: chuyển cả máy/ngày trong hai mode, restart, actual-sync rollback/retry/counters, reviewed row giữ payload, reason alias/inclusion/exclusion/change. Case rỗng vẫn COLLECTING/DAILY_PARTIAL theo nghiệp vụ hiện tại. Native process test xác minh giao thức crash/concurrency chung; chưa hard-kill riêng scenario chuyển evidence, chưa deploy/live verify.


## AI Rescue bulk HTTP và MySQL snapshot — 23/10/2026

Fixture2062 không provider: count/preview còn full backlog report~1.8s, execute enqueue đồng bộ38.865s. Không quy timeout cho AI provider khi chưa trace/log. SQL taxonomy của dashboard cho count/readonly snapshot IDs; preview lớn phải nói eligibility chưa kiểm tra, không trình bày upper bound thành số chắc chắn. Chốt actor/UUID/IDs, enqueue theo unit với attempt/item/cursor atomic; progress OCR tách khỏi enqueue, giữ lifecycle/fairness cũ.

Native concurrency bắt lỗi firstOrCreate đọc fallback trong snapshot REPEATABLE READ cũ (MySQL1062/HTTP500). Catch unique SAU rollback và đọc lại ngoài transaction thắng;302/302 cùng run. Test DB cách ly vẫn cần fake-storage riêng cho mỗi process, nếu không cleanup có thể làm mất ảnh fixture của test khác. Final SQLite568/5625, MySQL40/452, Python42 PASS; native2058eligible/2062selection, real crash78/77, lock waits,2058mock OCR calls/callback replay/no duplicate attempts. LOCAL ONLY, hosting stage/live quota/provider billing not verified.


## PDF external-tool warning và nguồn bất biến — 29/10/2026

Upload PDF1.6 thực thất bại0/? vì generic isSuccessful coi qpdf exit3 như lỗi; stderr xác minh chỉ thiếu xref-stream self-entry, không phải launch/timeout/encryption. Không bỏ qua mọi nonzero: exit2/unknown warning vẫn từ chối; chỉ warning đã nhận diện mới tạo derivative, bắt buộc fresh qpdf --check exit0, đọc mọi trang, geometry/count/rotation khớp và xuất/mở lại. Không rasterize/ghi đè nguồn. Diagnostics command/version/exit/stdout/stderr/timeouts riêng; lọc metadata cá nhân từ pdfinfo.

Regression byte fixture tối thiểu không PII tái hiện đúng lỗi trước sửa, qua sau sửa; negative error2/unknown warning/failed output-check bị chặn. Retry nguồn cũ idempotent. Actual16page scan: hash/bytes nguồn giữ nguyên, thumbnail/processed render giống nhau; export đúng thứ tự và image-stream hashes/dimensions giữ nguyên. Phân loại thử rollback để tránh bịa dữ liệu xác nhận. Targeted25/392, full593/6017 PASS; LOCAL only, không thay acceptance mẫu khác hay hosting proof.
