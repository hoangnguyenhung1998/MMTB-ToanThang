# MMTB --- Hướng dẫn vận hành cho Codex / AI Agent

## 1. Mục đích

Repository này chứa hệ thống MMTB phục vụ quản lý máy móc thiết bị và
các luồng tự động hóa liên quan.

File này chứa các quy tắc cấp project để Codex/AI Agent làm việc: -
chính xác; - an toàn; - đúng phạm vi; - bảo toàn nghiệp vụ; - kiểm thử
phù hợp; - tiết kiệm context/token; - dễ review và bảo trì.

Không đưa toàn bộ nghiệp vụ chi tiết vào file này. Các quy trình chuyên
biệt nằm trong `.agents/skills/` và chỉ được đọc khi nhiệm vụ có liên
quan.

------------------------------------------------------------------------

## 2. Thứ tự ưu tiên

Khi có nhiều mục tiêu cạnh tranh, ưu tiên theo thứ tự:

1.  Tính đúng đắn.
2.  An toàn dữ liệu và production.
3.  Yêu cầu trực tiếp, mới nhất của người dùng.
4.  Bảo toàn nghiệp vụ hiện tại.
5.  Thay đổi tối thiểu, đúng phạm vi.
6.  Kiểm thử phù hợp với mức rủi ro.
7.  Khả năng bảo trì.
8.  Tối ưu context/token.
9.  Tốc độ thực hiện.

Không hy sinh tính đúng đắn, dữ liệu, bảo mật hoặc kiểm thử cần thiết
chỉ để tiết kiệm token hoặc làm nhanh hơn.

------------------------------------------------------------------------

## 3. Nguồn code chuẩn và môi trường

### 3.1. Nguồn code chuẩn

-   GitHub là nguồn code chuẩn (source of truth).
-   Code phải được sửa và kiểm thử ở local trước khi push lên GitHub.
-   Không coi code đang nằm trên production là nguồn code chuẩn.
-   Khi thông tin lịch sử trong conversation/Phase cũ mâu thuẫn với
    repository hiện tại, ưu tiên trạng thái repository và yêu cầu mới
    nhất sau khi xác minh.

### 3.2. Máy phát triển

Project có thể được phát triển trên:

-   PC công ty --- code và test.
-   PC ở nhà --- code và test.

Trước khi bắt đầu làm việc trên một máy phát triển:

1.  Kiểm tra `git status`.
2.  Xác nhận branch hiện tại.
3.  Xác nhận local đang dựa trên trạng thái upstream mong muốn.
4.  Bảo toàn các thay đổi local không liên quan.
5.  Không tự ghi đè, discard hoặc stash thay đổi của người dùng nếu chưa
    có lý do rõ ràng.

### 3.3. Laptop chạy 24/24

Laptop 24/24 chủ yếu là máy runtime cho:

-   Zalo Collector.
-   OCR workers.
-   Reconciliation/automation workers.
-   Các tiến trình chạy nền kết nối với Laravel.

Không coi laptop này là môi trường development thông thường.

Luồng cập nhật thông thường:

`PC development → test local → GitHub → cập nhật có kiểm soát trên laptop runtime`

### 3.4. Production

-   Laravel production chạy trên hosting.
-   Chỉ triển khai production sau khi đã kiểm tra local.
-   Không sửa trực tiếp production trừ khi người dùng yêu cầu rõ ràng.
-   Không tự `git pull`, migrate, restart service/worker hoặc thay đổi
    cấu hình production nếu chưa được yêu cầu.

Luồng ưu tiên:

`Local/Branch → Implement → Targeted Test → Regression Test khi cần → Review Diff → Commit/Push có phê duyệt → Merge → Deploy có phê duyệt → Production Verification`

------------------------------------------------------------------------

## 4. Quyền tự chủ mặc định

Khi được giao một nhiệm vụ code, Agent được phép tự thực hiện các thao
tác local an toàn sau:

-   Đọc code liên quan.
-   Đọc test liên quan.
-   Đọc tài liệu project có liên quan.
-   Nạp Skill phù hợp từ `.agents/skills/`.
-   Sửa code local cần thiết cho nhiệm vụ.
-   Thêm hoặc cập nhật test liên quan.
-   Chạy targeted tests.
-   Chạy test rộng hơn khi phạm vi/rủi ro yêu cầu.
-   Sửa regression do chính thay đổi hiện tại gây ra.
-   Kiểm tra final diff.
-   Dùng các lệnh Git chỉ đọc để xác minh trạng thái/history/diff.

Không cần dừng để xin xác nhận cho các thao tác local thông thường,
không phá hủy dữ liệu.

Chỉ cần hỏi lại khi:

-   Yêu cầu có nhiều cách hiểu và mỗi cách làm thay đổi nghiệp vụ đáng
    kể.
-   Thao tác có thể xóa hoặc làm thay đổi dữ liệu không thể khôi phục.
-   Cần truy cập hoặc thay đổi production.
-   Cần secret, credential hoặc quyền bên ngoài chưa có.
-   Thay đổi sẽ cố ý phá một business rule hiện có nhưng yêu cầu chưa
    nói rõ rule đó phải thay đổi.
-   Có nguy cơ regression nghiêm trọng mà không thể giảm thiểu an toàn
    từ code/test hiện có.

Ưu tiên hoàn thành nhiệm vụ thay vì liên tục xin phép cho các thao tác
local an toàn.

------------------------------------------------------------------------

## 5. Các hành động bắt buộc phải được phê duyệt

Không tự thực hiện nếu chưa được người dùng yêu cầu hoặc phê duyệt rõ
ràng:

-   `git push`.
-   Merge branch.
-   Tạo hoặc merge Pull Request nếu hành động đó làm thay đổi remote.
-   Deploy production.
-   `git pull` trên production.
-   Chạy migration trên production.
-   Restart worker/service production hoặc runtime.
-   Sửa `.env` production.
-   Thay đổi credential/secret production.
-   Xóa dữ liệu production.
-   Thực hiện database operation có tính phá hủy.
-   Rewrite Git history.
-   Force push.
-   Xóa branch còn chứa code chưa merge.
-   Reset/discard thay đổi local chưa được xác nhận.
-   Chạy command destructive hoặc không thể hoàn tác.

Các thao tác đọc, phân tích và test local không phá hủy dữ liệu không
cần xin phép.

------------------------------------------------------------------------

## 6. Nguyên tắc đọc project và quản lý context

### 6.1. Không quét toàn bộ repository theo mặc định

Bắt đầu từ điểm vào có khả năng liên quan nhất và mở rộng theo thứ tự:

`Yêu cầu → Entry point → Dependency trực tiếp → Service/Model liên quan → Test liên quan → Subsystem liên quan → Phần khác chỉ khi có bằng chứng cần thiết`

Ví dụ với lỗi Zalo ingestion:

`Collector → API ingestion → Controller/Service → messageId/SHA256 → Database → Test liên quan`

Không cần đọc Gate Pass, OCR CCCD, Excel hoặc module khác nếu chưa phát
hiện dependency trực tiếp.

Chỉ chủ động quét phạm vi lớn/toàn repository khi nhiệm vụ thực sự là:

-   audit kiến trúc;
-   audit bảo mật;
-   refactor lớn;
-   nâng cấp framework/dependency;
-   điều tra lỗi liên nhiều subsystem;
-   thay đổi cross-system;
-   hoặc người dùng yêu cầu rõ ràng.

### 6.2. Ưu tiên context chính xác hơn context lớn

Context window lớn không có nghĩa là phải đưa toàn bộ project vào
context.

Luôn mở rộng theo hướng:

`Phạm vi nhỏ → Dependency trực tiếp → Subsystem → Cross-system → Toàn repository`

Không làm ngược lại nếu không có lý do.

### 6.3. Tái sử dụng context đã xác minh

Trong cùng Phase hoặc subsystem:

-   Tận dụng architecture đã xác minh.
-   Tận dụng quyết định đã chốt.
-   Không đọc lại tài liệu lớn không thay đổi nếu không cần.
-   Không khám phá lại thông tin vừa xác minh.

Khi chuyển sang subsystem khác, phải xác minh lại các assumption quan
trọng.

### 6.4. Chống context cũ/context bẩn

Nếu context lịch sử gây:

-   nhầm requirement;
-   sử dụng quyết định đã bị hủy;
-   nhắc code cũ không còn tồn tại;
-   sửa ngoài scope;
-   nhầm subsystem;

thì ưu tiên theo thứ tự:

1.  Yêu cầu mới nhất của người dùng.
2.  Code hiện tại.
3.  Test hiện tại.
4.  Skill hiện tại.
5.  Tài liệu project hiện tại.
6.  Thông tin lịch sử chỉ dùng để tham khảo.

Không coi conversation hoặc Phase cũ là đúng hơn repository hiện tại.

------------------------------------------------------------------------

## 7. Quy tắc đọc Skills và tài liệu

Không đọc tất cả Skills theo mặc định.

Thứ tự:

1.  Đọc `AGENTS.md`.
2.  Xác định loại nhiệm vụ.
3.  Đọc Skill trực tiếp liên quan.
4.  Chỉ đọc thêm Skill khác khi phát hiện dependency thực sự.

Các Skill hiện tại của project:

### `.agents/skills/laravel-mmtb/SKILL.md`

Dùng cho: - Laravel MMTB. - Quản lý máy. - API. - Migration. - UI
Laravel. - Laravel tests.

### `.agents/skills/journal-ocr/SKILL.md`

Dùng cho: - OCR ảnh nhật trình. - Đọc ngày/giờ. - Nội dung công việc. -
Chuẩn hóa OCR.

### `.agents/skills/zalo-collector/SKILL.md`

Dùng cho: - Zalo Collector. - zca-js. - Tài khoản/nhóm. - Ingestion. -
Chống duplicate.

### `.agents/skills/automation-workers/SKILL.md`

Dùng cho: - OCR workers. - Reconciliation. - Automation workers. -
Polling. - Job. - Retry. - Runtime worker.

### `.agents/skills/deployment/SKILL.md`

Dùng cho: - Git workflow. - Hai máy development. - Laptop runtime. -
GitHub. - Hosting. - Deploy. - Kiểm tra sau deploy.

Ví dụ routing:

-   Task Laravel/API/UI/migration → `laravel-mmtb`.
-   Task OCR nhật trình → `journal-ocr`.
-   Task Zalo Collector → `zalo-collector`.
-   Task reconciliation/worker → `automation-workers`.
-   Task Git/deploy/runtime update → `deployment`.

Nếu một task liên quan nhiều subsystem, chỉ nạp Skill thứ hai khi
dependency đã được xác định hoặc phạm vi nhiệm vụ rõ ràng yêu cầu.

------------------------------------------------------------------------

## 8. Nguyên tắc thay đổi code

Với mỗi nhiệm vụ:

1.  Hiểu hành vi được yêu cầu.
2.  Xác định phạm vi và non-goal khi cần.
3.  Chỉ đọc code/tài liệu có liên quan.
4.  Xác định business rule và test bị ảnh hưởng.
5.  Thực hiện thay đổi nhỏ nhất nhưng đầy đủ để đáp ứng yêu cầu.
6.  Không refactor phần không liên quan.
7.  Giữ backward compatibility trừ khi nhiệm vụ chủ động thay đổi nó.
8.  Chạy validation/test phù hợp.
9.  Review final diff.
10. Báo cáo thay đổi và ảnh hưởng triển khai.

Không tự:

-   refactor code không liên quan;
-   đổi tên class/function/variable không cần thiết;
-   format lại file không liên quan;
-   sửa UI ngoài yêu cầu;
-   thay đổi kiến trúc nếu task không yêu cầu;
-   nâng cấp dependency không liên quan;
-   sửa bug khác chỉ vì tình cờ phát hiện;
-   thay đổi nghiệp vụ hiện tại nếu chưa được yêu cầu;
-   đổi public interface, database field, API contract, route hoặc
    operational command ngoài scope.

Nếu phát hiện vấn đề ngoài scope:

1.  Không tự sửa.
2.  Ghi nhận.
3.  Báo ở cuối nhiệm vụ.
4.  Chỉ xử lý nếu nó chặn trực tiếp nhiệm vụ hiện tại hoặc người dùng
    cho phép.

Diff cuối cùng phải tập trung và dễ review.

------------------------------------------------------------------------

## 9. Bảo toàn nghiệp vụ

Business rule hiện có được coi là hợp lệ trừ khi yêu cầu hiện tại chủ
động thay đổi nó.

Không suy diễn business rule mới chỉ từ một chi tiết implementation.

Nếu code, test và tài liệu mâu thuẫn:

1.  Ưu tiên yêu cầu được phê duyệt/mới nhất.
2.  Kiểm tra Skill nghiệp vụ liên quan.
3.  Kiểm tra code và test hiện tại.
4.  Nếu vẫn chưa chắc chắn, ưu tiên bảo toàn hành vi production hiện
    tại.
5.  Báo rõ mâu thuẫn trong kết quả cuối.

Không lấy rule từ Phase cũ đưa trở lại hệ thống nếu chưa xác minh nó vẫn
còn hiệu lực.

------------------------------------------------------------------------

## 10. Quy trình debug

Khi xử lý bug:

1.  Xác định triệu chứng thực tế.
2.  Reproduce lỗi nếu có thể.
3.  Xác định execution path.
4.  Kiểm tra phạm vi nhỏ nhất có liên quan.
5.  Đưa ra giả thuyết root cause.
6.  Kiểm chứng giả thuyết bằng code, log hoặc test.
7.  Xác định root cause trước khi sửa nếu có thể.
8.  Thực hiện minimal fix.
9.  Thêm hoặc cập nhật regression test nếu phù hợp.
10. Chạy targeted test.
11. Mở rộng test theo mức rủi ro.
12. Review final diff.

Không sửa code liên tục theo kiểu thử-sai khi chưa có giả thuyết nguyên
nhân hợp lý.

Nếu bằng chứng mới phủ định giả thuyết cũ, cập nhật giả thuyết thay vì
cố ép dữ liệu theo kết luận ban đầu.

------------------------------------------------------------------------

## 11. Chiến lược kiểm thử

Ưu tiên kiểm thử theo cấp độ:

`Targeted Test → Module/Subsystem Test → Regression Test → Full Test Suite khi cần`

### 11.1. Targeted Test

Ưu tiên chạy trước khi:

-   sửa bug cục bộ;
-   thay validation;
-   sửa Controller/Service cụ thể;
-   sửa UI/form cụ thể;
-   thêm regression test;
-   thay đổi một chức năng độc lập.

Ví dụ:

-   Laravel feature/unit test liên quan.
-   Python test của worker liên quan.
-   Collector test cho thay đổi Collector.
-   API test cho ingestion/automation.

Không chạy `php artisan test` toàn bộ sau mọi thay đổi nhỏ nếu targeted
test đã đủ cho bước đầu.

### 11.2. Module/Subsystem Test

Chạy khi thay đổi ảnh hưởng nhiều thành phần trong cùng
module/subsystem.

Ví dụ:

`Collector → Ingestion → Database`

hoặc:

`OCR → Reconciliation`

### 11.3. Full Test Suite

Chạy suite rộng/full khi:

-   hoàn thành một Phase lớn;
-   thay đổi shared infrastructure;
-   authentication/authorization thay đổi;
-   schema/database behavior thay đổi quan trọng;
-   common/shared service thay đổi;
-   nhiều subsystem cùng bị ảnh hưởng;
-   targeted test cho thấy nguy cơ regression;
-   chuẩn bị merge/deploy production;
-   mức rủi ro thay đổi đủ lớn.

Không coi nhiệm vụ hoàn thành chỉ vì code compile hoặc không có syntax
error.

Không bỏ kiểm thử cần thiết chỉ để tiết kiệm token.

------------------------------------------------------------------------

## 12. Tối ưu terminal, Git output và log

Output terminal cũng trở thành context. Giữ output có mục tiêu.

Ưu tiên:

``` bash
git status
git diff -- <file-liên-quan>
git log --oneline -10 -- <file-liên-quan>
```

thay vì dump Git history hoặc diff toàn repository nếu không cần.

Khi chạy test, ưu tiên output đủ để xác nhận:

-   test nào đã chạy;
-   PASS/FAIL;
-   lỗi liên quan.

Không tạo verbose output lớn nếu không giúp điều tra.

### Đọc log

Không đọc toàn bộ file log lớn theo mặc định.

Thứ tự:

1.  Xác định timestamp/job/request/error liên quan.
2.  Đọc vùng nhỏ xung quanh.
3.  Tìm exception/error liên quan.
4.  Mở rộng phạm vi nếu chưa đủ bằng chứng.

Không đưa hàng nghìn dòng log vào context nếu lỗi có thể xác định từ một
vùng nhỏ.

------------------------------------------------------------------------

## 13. Quy tắc Git

Trước khi sửa:

-   Kiểm tra `git status`.
-   Xác định branch hiện tại.
-   Kiểm tra working tree.
-   Bảo toàn mọi thay đổi không thuộc task.

Trong quá trình làm:

-   Giữ diff nhỏ.
-   Không overwrite thay đổi khác.
-   Không rewrite history nếu chưa được yêu cầu.
-   Không force push.
-   Không tự merge production.
-   Không tự push nếu chưa được phê duyệt.

Trước khi hoàn thành:

-   Review `git diff`.
-   Xác nhận không có file ngoài scope.
-   Chạy test phù hợp.

Không commit:

-   password;
-   API key;
-   token;
-   cookie;
-   private key;
-   `.env` chứa secret;
-   credential production;
-   runtime/session data.

------------------------------------------------------------------------

## 14. Database và migration

Khi thay đổi database:

-   Ưu tiên migration additive và backward-compatible.
-   Không sửa migration lịch sử có khả năng đã chạy production nếu không
    có lý do đặc biệt và kế hoạch rõ ràng.
-   Bảo toàn dữ liệu hiện có.
-   Xem xét rollback.
-   Xem xét thứ tự deploy.
-   Kiểm tra dữ liệu hiện có.
-   Kiểm tra `NULL`/default.
-   Kiểm tra index.
-   Kiểm tra unique constraint.
-   Kiểm tra foreign key.
-   Đánh giá ảnh hưởng production.
-   Báo rõ nếu deployment cần `php artisan migrate --force`.

Phân biệt rõ:

-   Schema Migration.
-   Data Migration.
-   One-time Repair Script.

Không tự sửa/xóa dữ liệu lịch sử production.

Không chạy migration phá hủy dữ liệu trên production nếu chưa được phê
duyệt.

------------------------------------------------------------------------

## 15. Log, file sinh tự động và runtime data

Không commit:

-   Secret.
-   Session file.
-   Runtime cache.
-   OCR cache/output nếu không chủ động version.
-   Worker runtime state.
-   Zalo authentication/session material.
-   Ảnh tạm.
-   Local log.
-   File riêng theo từng máy.

Giữ tương thích format runtime data trừ khi nhiệm vụ chủ động migrate.

Nếu thay đổi format runtime data, phải đánh giá backward compatibility
và ảnh hưởng tới worker/laptop runtime.

------------------------------------------------------------------------

## 16. Bảo mật

Không được hiển thị công khai, log, copy vào tài liệu hoặc commit:

-   Zalo session credential.
-   API token.
-   Production credential.
-   Database password.
-   Authentication cookie.
-   Private key.
-   Các secret khác.

Sử dụng cơ chế environment/config hiện có.

Nếu thiếu secret cần thiết, báo thiếu thay vì tự tạo giá trị giả.

Không:

-   hard-code credential;
-   làm yếu authentication;
-   bypass authorization;
-   bỏ validation chỉ để test pass;
-   bỏ rate limit chỉ để chức năng chạy.

------------------------------------------------------------------------

## 17. Dependency

Không thêm package/library mới nếu project hiện tại đã có khả năng giải
quyết hợp lý.

Trước khi thêm hoặc nâng cấp dependency:

1.  Xác định có thực sự cần không.
2.  Kiểm tra compatibility.
3.  Kiểm tra ảnh hưởng security.
4.  Kiểm tra maintenance.
5.  Kiểm tra ảnh hưởng module hiện tại.

Không thực hiện broad dependency upgrade trong một bug fix hoặc feature
không liên quan.

------------------------------------------------------------------------

## 18. Phase lớn và thay đổi kiến trúc

Đối với Phase lớn, feature lớn hoặc thay đổi cross-system, không code
ngay khi phạm vi chưa rõ.

Trước tiên xác định:

-   Goal.
-   Scope.
-   Non-goals.
-   Current Architecture.
-   Affected Systems.
-   Dependencies.
-   Data Impact.
-   Backward Compatibility.
-   Risks.
-   Implementation Plan.
-   Acceptance Criteria.
-   Testing Strategy.
-   Deployment/Rollback.

Sau đó chia thành các sub-phase độc lập và có thể test khi phù hợp.

Ví dụ:

``` text
Phase X
├── X.1 Database
├── X.2 Backend
├── X.3 Worker
├── X.4 UI
├── X.5 Integration
└── X.6 Regression Tests
```

Không triển khai một Phase lớn bằng một thay đổi khổng lồ nếu có thể
chia nhỏ an toàn.

------------------------------------------------------------------------

## 19. Tiêu chí hoàn thành

Không coi nhiệm vụ hoàn thành chỉ vì đã viết code.

Nếu nhiệm vụ không quy định khác, chỉ coi implementation hoàn thành khi
phù hợp với phạm vi:

-   Hành vi được yêu cầu đã triển khai.
-   Root cause đã được xử lý nếu là bug.
-   Các hành vi liên quan hiện có vẫn được bảo toàn.
-   Acceptance criteria đạt.
-   Targeted tests PASS.
-   Regression/module/full tests cần thiết PASS.
-   Regression do thay đổi hiện tại gây ra đã được sửa.
-   Final diff đã được review.
-   Final diff không chứa thay đổi ngoài phạm vi.
-   Không đưa secret/runtime data vào code.
-   Đã xác định ảnh hưởng database/deployment/runtime.
-   Migration an toàn nếu có.
-   Đã nêu rõ phần cần test thủ công nếu có.
-   Rủi ro còn lại đã được ghi nhận.

------------------------------------------------------------------------

## 20. Báo cáo sau khi hoàn thành

Báo cáo ngắn gọn, ưu tiên thông tin có thể kiểm chứng:

### Kết quả

-   Đã làm gì.

### Root cause

-   Nguyên nhân nếu đây là bug.

### File thay đổi

-   `path/file`
-   `path/file`

### Kiểm thử

-   `command` → PASS/FAIL.

### Database / Migration

-   Không ảnh hưởng; hoặc mô tả ảnh hưởng.

### Runtime / Worker / Deployment

-   Không ảnh hưởng; hoặc mô tả ảnh hưởng và bước cần thực hiện.

### Kiểm tra thủ công

-   Không cần; hoặc các bước cần người dùng kiểm tra.

### Rủi ro / Việc tiếp theo

-   Không có; hoặc nội dung cần lưu ý.

Không viết lại một bản giải thích dài về code nếu thông tin đã thể hiện
rõ trong diff.

------------------------------------------------------------------------

## 21. Điều kiện phải dừng

Dừng và hỏi người dùng khi:

-   Có hai cách hiểu nghiệp vụ khác nhau và mỗi cách dẫn tới hành vi
    khác nhau đáng kể.
-   Cần thao tác destructive nhưng chưa được cho phép.
-   Có nguy cơ mất dữ liệu.
-   Thiếu credential/access bắt buộc.
-   Yêu cầu mới xung đột với critical domain rule nhưng chưa rõ ưu tiên.
-   Fix dự kiến có nguy cơ regression nghiêm trọng mà chưa có cách giảm
    thiểu rõ ràng.
-   Cần thay đổi production ngoài phạm vi đã được cho phép.

Không hỏi lại những vấn đề có thể xác minh an toàn từ:

-   code;
-   test;
-   `AGENTS.md`;
-   Skill;
-   documentation;
-   convention hiện tại.

------------------------------------------------------------------------

## 22. Nguyên tắc vàng về context và token

Cung cấp cho AI **đủ context để quyết định đúng**, nhưng không phải toàn
bộ context đang tồn tại.

Không tối ưu theo kiểu:

`Model mạnh nhất + Reasoning cao nhất + Đọc toàn repository + Đọc toàn bộ log + Full test mọi lần`

Mà tối ưu theo:

`Yêu cầu rõ → đúng phạm vi → đúng Skill → context mục tiêu → minimal fix → targeted test → mở rộng theo rủi ro → review → hoàn thành`

Mục tiêu cuối cùng:

`CHÍNH XÁC → AN TOÀN → ÍT REGRESSION → ÍT THAY ĐỔI THỪA → ÍT CONTEXT THỪA → TIẾT KIỆM TOKEN → DỄ REVIEW → DỄ BẢO TRÌ`
