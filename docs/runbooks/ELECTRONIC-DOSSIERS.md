# Hồ sơ điện tử — vận hành Phase18

## Phạm vi và quyền

Review29/10: auth-only KHÔNG đủ cho hồ sơ nhạy cảm: repo chưa có role/membership theo công ty và route đăng ký vẫn mở. Module nay yêu cầu cả `auth` và Gate `manage-dossiers`, chỉ cho ID có trong `DOSSIERS_ALLOWED_USER_IDS` do operator cấu hình. Mặc định danh sách trống → từ chối toàn bộ; đăng ký/đăng nhập không tự cấp quyền. Không đổi phân quyền các module cũ. Bộ PDF đã xuất tiếp tục chỉ người yêu cầu được xem/tải/retry, kể cả khi người khác cũng có quyền module. Gate áp dụng cả xem nguồn, thumbnail, bản xử lý, Excel, CRUD và export/retry; menu phụ được ẩn với người không có quyền. Không có API cấp quyền từ request người dùng.

Không tự di chuyển/đọc hồ sơ public cũ như dữ liệu đã xác nhận. Trang hồ sơ điện tử có link về hồ sơ cũ; người vận hành upload lại PDF và xác nhận từng giấy. Không xóa file cũ trong đợt rollout này.

## Preflight local / hosting trước release có phê duyệt

1. Đúng reviewed HEAD; Composer install từ lock (`setasign/fpdf 1.9.0`, `setasign/fpdi 2.6.9`), PHP>=8.2, ext-fileinfo, zlib, XML/ZIP/mbstring cho Excel như hiện tại. Kiểm tra PHP web `upload_max_filesize` ít nhất50M, `post_max_size` và giới hạn body webserver có dư phần multipart (ví dụ64M); browser gửi riêng từng file, không cần nhận200file trong một request. Native CLI fixture không chứng minh giới hạn upload HTTP của hosting.
2. Disk `dossiers` là local, root `storage/app/dossiers-private`, nằm ngoài public; không tạo symlink, URL public hoặc `serve=true`. Web và CLI dùng cùng storage/mount; đủ dung lượng nguồn+thumbnail+bộ xuất, quyền chỉ cho user dịch vụ. Backup DB cùng bytes nguồn/exports, checksum và thử restore.
3. Cần Poppler `pdftoppm`, `pdfinfo` và qpdf CLI trên môi trường xử lý. `.env` riêng: `DOSSIERS_PDFTOPPM`, `DOSSIERS_PDFINFO`, `DOSSIERS_QPDF` là đường dẫn binary hợp lệ; `DOSSIERS_ALLOWED_USER_IDS` là các ID đã được người dùng phê duyệt. Local29/10 dùng Poppler bundled và qpdf12.3.2 đã có trong PDF24, không thêm/nâng dependency. Hosting phải xác minh binary/exec/quyền/phiên bản thực tế; không copy binary Windows lên Linux, không coi local proof là hosting proof.
4. Không loại PDF chỉ vì header1.6. Nếu FPDI đọc trực tiếp được thì dùng nguồn. Khi gặp compressed xref/object streams, queue tự gọi `qpdf --object-streams=disable --stream-data=preserve --deterministic-id` tạo file riêng `processed/<source ID>/...pdf`; không raster hóa, không hạ độ phân giải hoặc ép phiên bản1.4. PDF nguồn giữ bytes/hash. Kiểm tra số trang, kích thước/crop/rotation bằng pdfinfo trước/sau; ghi method/tool version/options/geometry/hash riêng. Manifest đóng băng cả nguồn lẫn processed path/hash. Thumbnail dùng nguồn; giao diện có link mở bản xử lý để đối chiếu trước xác nhận. Không tự giải mã. Exit2 và warning chưa nhận diện bị từ chối. Exit3 chỉ với cảnh báo thiếu xref self-entry đã xác minh mới được tạo bản xử lý riêng; bắt buộc qpdf --check bản xử lý exit0, FPDI đọc mọi trang, geometry khớp và xuất thử/mở lại đủ trang trước khi chấp nhận. Không dùng --warning-exit-0. Log riêng command/version/exit/stdout/stderr/timeout, không đưa raw diagnostics ra UI; pdfinfo log chỉ geometry để tránh metadata cá nhân. Không yêu cầu người dùng tự convert mỗi lần upload. Test cơ chế compressed1.6/render tương đương không thay acceptance hai scan thật. PDF xuất qua FPDI có thể không giữ chữ ký số/annotation tương tác; nguồn bất biến luôn được giữ.
5. Có2migration Phase18: `2026_10_28_000001_create_electronic_dossiers` (10 bảng + machines.dossier_group) và `2026_10_29_000001_add_dossier_pdf_processing` (metadata bản xử lý nullable). Migration additive, rollback có dữ liệu sẽ từ chối xóa metadata/history. Chạy đúng PHP/migration/cache chỉ trên môi trường được phê duyệt, không chạy reset/fresh. Main LOCAL29/10 còn7migration additive trướcPhase18: đã review, thử trên bản SQL restore cách ly rồi mới áp dụng cả9; không sửa dữ liệu nghiệp vụ cũ.
6. Queue riêng `dossiers` dùng bảng `dossier_jobs`; command `php artisan dossiers:work --max-time=50` chỉ drain queue này, cấu hình failure riêng `dossier_failed_jobs`. Scheduler mỗi phút, withoutOverlapping20phút. Không gọi toàn bộ schedule:run trong test local. Cron hiện có phải thực sự chạy `schedule:run`; `schedule:list` không chứng minh runtime.
7. Một job thumbnail xử lý một trang rồi nối job tiếp; lần đầu có thể chuẩn hóa cấu trúc PDF. Worker timeout900s / retry_after1020s, nguồn timeout240s, export900s, tries3/backoff30/120. max-time50 kiểm tra giữa job. Dùng `php artisan dossiers:work --watch` khi thử local để liên tục chờ riêng queue này; Ctrl+C kết thúc. Mặc định không có --watch vẫn drain bounded cho scheduler. Không chạy queue mặc định/Collector/OCR hoặc schedule:run toàn bộ để thử hồ sơ.
8. Telegram hồ sơ luôn opt-in riêng: `DOSSIERS_TELEGRAM_ENABLED=false`, `DOSSIERS_TELEGRAM_RECIPIENTS=` mặc định. Không kế thừa chat_id cảnh báo vận hành. Token tái dùng TELEGRAM_BOT_TOKEN qua config sẵn có. Recipient phải ID số hoặc @username hợp lệ. Mỗi ngày07:15 VN `dossiers:reminders`; chưa cấu hình không tạo delivery/gửi.

## Cách dùng

### Local công ty — 29/10/2026

DB ứng dụng mmtb đã được backup/restore kiểm tra rồi migrate additive; không reset/fresh. URL thử: `http://127.0.0.1:18180/dossiers`. Đăng nhập tài khoản hiện có đã được người dùng chỉ định; không tạo tài khoản/password thử trong DB ứng dụng. Chỉ allowlist local được cấp quyền, tài khoản còn lại bị403. Hai scan phải được đặt nguyên file tại `C:/laragon/www/mmtbver2/storage/app/private/phase18-samples/`; hiện chưa có, không đánh dấu nghiệm thu mẫu thật.

Nếu server chưa chạy, mở PowerShell thứ nhất:

```powershell
Set-Location C:/laragon/www/mmtbver2
$env:APP_URL='http://127.0.0.1:18180'
$env:ASSET_URL='http://127.0.0.1:18180'
& C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan serve --host=127.0.0.1 --port=18180 --no-reload
```

Mở PowerShell thứ hai cho riêng queue hồ sơ:

```powershell
Set-Location C:/laragon/www/mmtbver2
& C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan dossiers:work --watch
```

Hai process này đã được khởi động cho lượt thử29/10; không mở thêm nếu cổng/worker đang chạy. Ctrl+C ở terminal chủ quản để dừng. MySQL local cổng3306 phải chạy từ datadir Laragon đã xác minh; không trỏ sang DB từ xa hoặc đổiDB để vượt kiểm tra. Không dùng schedule:run toàn bộ vì có nhiều tác vụ khác. Kết thúc thao tác code không tự dừng server/worker đang cung cấp cho người dùng thử.

1. Menu **Hồ sơ điện tử** → **Tạo đợt upload PDF**. Chọn nhiều file, mỗi file tên mã tài sản.pdf; browser upload lần lượt, file lỗi có nút retry; file đã lưu xuất hiện trong đợt sau refresh. Checksum trùng mở lại nguồn đã có, không tạo bản sao. Không tạo máy tự động. NOT_FOUND/AMBIGUOUS phải chọn/xác nhận máy đúng thủ công sau xem nguồn.
2. Chờ queue xử lý → mở file → xem PDF gốc/thumbnail lớn, đối chiếu máy → **Lưu đối chiếu/nháp**. Có thể đóng/mở lại; chỉ thay đổi đã lưu được giữ.
3. Chọn trang bằng checkbox hoặc nhập danh sách theo thứ tự, ví dụ `1,2,5`. Chọn đúng từng tài xế; không suy tên/ID từ ảnh. Chọn nhóm chủ thể máy/tài xế/phân công, loại giấy, phạm vi phụ (main mặc định), xoay từng trang. Một giấy nhiều trang được giữ chung. Trang trắng bỏ có chủ đích trong form nguồn; không xóa bytes. Dùng chung giữa giấy khác nhau phải xác nhận trên cả hai; các phiên bản của cùng giấy không xem là hai giấy trùng.
4. Nhập ngày cấp, hiệu lực, chứng thực riêng; hạn `YYYY-MM-DD`, thời điểm `YYYY-MM-DD HH:MM:SS`, tháng `YYYY-MM`; tháng chỉ tính sau xác nhận cuối tháng. Không thời hạn/chưa biết chọn rõ. Không suy hạn từ ngày cấp hoặc loại giấy. Lưu nháp → xác nhận từng giấy → xác nhận toàn hồ sơ khi mọi trang đã phân loại/bỏ và không có nháp.
5. Danh mục cấu hình nhóm áp dụng/bắt buộc/mốc ngày/thứ tự. Seed không có yêu cầu bắt buộc pháp lý. Nhóm máy/xe trên từng máy do người dùng chọn, không tự đoán theo tên.
6. Thay giấy: upload scan mới, cùng loại/chủ thể/phạm vi phụ sẽ nối phiên bản; lý do bắt buộc, hiệu lực sau phiên bản xác nhận mới nhất. Hoặc ở nguồn cũ chọn **Tạo phiên bản sửa thông tin/hạn** để tạo bản mới tham chiếu bytes cũ. Không sửa bản đã xác nhận. Giấy tương lai chưa thay bản hiện tại; bản mới hết hạn/lưu trữ không tự lấy lại bản cũ. Lưu trữ/khôi phục có lý do và audit. Đang gia hạn ghi chú không làm còn hạn.

   Sửa nhầm chủ thể/loại/phạm vi phụ: mở chức năng sửa từ giấy đã xác nhận, chọn chủ thể đúng, lý do và thời điểm hiệu lực hiện tại/quá khứ. Khi xác nhận, tạo chuỗi đúng và lưu trữ toàn bộ chuỗi sai có audit (kể cả bản tương lai để tránh áp dụng lại). Không thay metadata/file của phiên bản cũ hoặc manifest đã xuất. Lưu nháp sửa chủ thể chưa thay bản cũ; không hỗ trợ chuyển chủ thể tương lai. Liên kết `corrects_id` giữ truy nguyên.
7. Trang máy → thêm phân công từng người, có ngày bắt đầu/kết thúc; kết thúc riêng người cần chuyển, giữ người khác. Trường tài xế chính cũ vẫn phục vụ các màn hình cũ; đổi tài xế chính nay chỉ đóng lịch sử đúng tài xế chính, không đóng đồng nghiệp. Hồ sơ cá nhân tái dùng trên máy mới. Giấy phân công chỉ vào đúng assignment ID đang hiệu lực.
8. Trang tài xế → chọn sender ID thực và xác nhận. Màn hình người gửi/máy mặc định có link hồ sơ và thao tác liên kết. Không tự ghép theo tên; sender liên kết người khác bị chặn, muốn đổi phải bỏ liên kết có lý do trước. Không thay mapping ảnh, OCR, backlog hoặc phân công. Không có Zalo vẫn quản lý giấy bình thường.
9. Trang máy → chọn giấy và tài xế hiện tại → xem trước → sắp lên/xuống → đọc/chấp nhận cảnh báo → yêu cầu gộp. Không chọn giấy người cũ, assignment cũ hoặc bản đã thay. Giấy hết hạn/chưa xác nhận/chưa hiệu lực chỉ xuất khi chọn và chấp nhận cảnh báo. Manifest snapshot đúng phiên bản/thứ tự/trang/xoay/checksum, các trang dùng chung giống nhau chỉ xuất một lần. Đóng trang không mất yêu cầu; retry cùng manifest. Tên file mã máy-ngày.pdf; mở lại bộ cũ không rebuild theo giấy hiện tại.
10. Xuất Excel có tổng hợp máy/BCH/công trình/tài xế và chi tiết từng giấy, gồm dòng thiếu bắt buộc, độ chính xác hạn/ngày còn lại/trạng thái cùng engine UI. Chuỗi được ghi dạng text để không thực thi công thức người nhập. Không xuất giấy tài xế cũ trong báo cáo máy hiện tại; lịch sử riêng vẫn xem được.

## Failure / retry / bảo toàn

- Nguồn lưu hash/bytes/tên/uploader/batch; không có endpoint xóa nguồn. FKs restrict các tham chiếu lịch sử. Xóa tài xế/máy đang có hồ sơ mới bị DB chặn để giữ lịch sử; phải xử lý lưu trữ qua quy trình riêng.
- Job lỗi riêng từng source/export; UI retry không tạo source/version/export mới. Queue chỉ ghi tiến độ khi trang thành công. DB row lock chống hai job cùng xuất hoặc mất cursor. Crash sau lưu bytes nhưng trước DB commit: retry ghi lại artifact cùng ID; bản nguồn không đổi. Dọn file tạm chỉ `exports/tmp-UUID.pdf` do job tạo trong finally; không xóa nguồn/manifest. Sau hard kill có thể còn temp; maintenance chỉ được dọn prefix tmp đã quá tuổi sau xác nhận không job hoạt động, không có auto recursive cleanup.
- Các lỗi source ghi source.status=failed; lỗi xuất ghi export.status=failed, queue thất bại lưu private dossier_failed_jobs (không expose exception chứa config). Retry qua màn hình sau kiểm tra môi trường. Trường hợp đã lưu DB nhưng queue dispatch không thành công, mở source/export queued rồi retry; không upload lại để tạo bản sao.
- Delivery unique(version,milestone,recipient), gửi thành công không gửi lại; lỗi từ chối rõ ràng thử tối đa3 lần. Sending được commit trước network. Timeout/5xx hoặc process chết sau gửi giữ uncertain/sending, không tự replay để tránh gửi trùng. Operator phải kiểm tra Telegram/log rồi phê duyệt xử lý riêng; không tự reset các hàng này. Chưa có mạng thì vẫn nhập/xuất hồ sơ bình thường.
- Ngày hết hạn inclusive qua23:59:59.999999 VN; thời điểm exact inclusive, +1microsecond hết hạn. Ngày còn lại là chênh ngày lịch VN; giấy hết hạn trong ngày có thể còn0 ngày nhưng status expired. Không dùng chứng thực tính hạn. Month chưa xác nhận luôn review.

## Release acceptance

Local synthetic tests không thay thế hai scan thật hoặc hosting proof. Trên môi trường được cho phép: kiểm tra scan thật 24/22trang, trang ngang/trắng/hai mặt/nhiều trang; checksum trước/sau, thumbnail, tạo/thay giấy tương lai, hai tài xế, preview và mở PDF tải về, Excel thiếu giấy, session hết hạn/file private, chạy queue từ cron sau đóng browser. Telegram vẫn để trống đến khi có recipient được xác nhận. Không label DEPLOYED/LIVE VERIFIED trước evidence.

Historical28/10: full589/5972 và targeted21/347 PASS; native SQLite queue/source/export PASS. Hai fixture thể tích24/22trang14,4/13,2MB dùng comment padding, không phải scan thực. Follow-up29/10: MySQL cách ly6pairs/12processes PASS (hai lần xác nhận, hai thay thế, hai thứ tự export/replacement race, request retry và job retry); chuỗi7versions/1record/1export cùng audit/source hash giữ nguyên. qpdf cơ chế PDF1.6 compressed normalization/render-equality PASS; real scans vẫn thiếu. Chưa xác minh hosting. Composer audit trước đó47advisories ở12dependency nền, không có2package PDF mới; không nâng cấp ngoài scope. Giới hạn encrypted vẫn áp dụng; compressed streams nay được qpdf chuẩn hóa tự động, không ép người dùng tự đổi1.4.


## Incident LOCAL T-XX0709 — 29/10/2026

Nguồn1/đợt08:02:26 đã retry tại chỗ và ready16/16; không cần upload lại. qpdf12.3.2 exit3 do thiếu xref-stream self-entry; bản xử lý qua check exit0 và kiểm tra nghiêm ngặt ở trên. Nguồn14727818bytes/checksum giữ nguyên,16thumbnail, PDF xuất16trang và bộ phân loại4trang đúng thứ tự14,15,16,13 đã mở/render; image-stream hashes/dimensions giữ nguyên, không giảm chất lượng. Phân loại test rollback, người dùng tự xác nhận dữ liệu thật tại /dossiers/sources/1. Regression25/392 và full593/6017 PASS; không thay acceptance hai scan còn thiếu. Chỉ reload worker hồ sơ, không chạm Collector/OCR/Telegram/production.


## Phase18.1 — cấu hình AI riêng và chạy thử LOCAL (30/10/2026)

**Hoàn thiện tích hợp; chưa kiểm thử kết nối/AI thực tế.** Người dùng yêu cầu chỉ mock, không gọi API/không phát sinh chi phí. Không lấy journal-worker/.env hoặc credential laptop. Bridge `journal-worker/scripts/dossier-vision.py` dùng lại `JournalVisionClient.request_json` và giao thức chat/completions JSON; không khởi động/sửa worker Journal. Prompt/schema/job/raw/status hồ sơ độc lập.

### Chuẩn bị schema LOCAL

Có migration additive mới `2026_10_30_000001_create_dossier_ai.php`, không sửa migration Phase18 cũ. Kết nối MySQL3306 hiện không truy cập được trong phiên30/10, nên chưa chạy backup/migration DB ứng dụng. Local web cũng không được quan sát đang chạy; URL dưới đây dùng sau khi khởi động. Không coi mocks là bằng chứng DB ứng dụng đã nâng cấp.

Sau khi anh bật đúng MySQL Laragon LOCAL, mở PowerShell tại repo, dùng helper có guard (không start/stop service, không gọi AI):

```powershell
Set-Location C:/laragon/www/mmtbver2
$phpDossier='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
# Kiểm tra trước, chưa ghi DB:
& $phpDossier tools/prepare-dossier-ai-local.php
# Thư mục backup riêng NGOÀI C:/laragon/www, không public/không Git:
$dossierBackup=Join-Path $env:LOCALAPPDATA 'MMTB-local-backups'
New-Item -ItemType Directory -Force -Path $dossierBackup | Out-Null
& $phpDossier tools/prepare-dossier-ai-local.php --apply --backup-dir="$dossierBackup" --mysqldump='C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe'
```

Guard yêu cầu APP_ENV=local, mysql127.0.0.1:3306/mmtb, không DB_URL; datadir Laragon+UUID auto.cnf+hostname phải trùng máy. Nếu DB/config khác thì dừng để xác minh, không sửa guard chỉ để chạy qua. Script kiểm tra Phase18 prerequisites, backup SQL theo single-transaction (chỉ InnoDB), exit0/kích thước/inventory đủ bảng/trailer và SHA256; proof.json cạnh SQL. Sau đó chỉ migrate đúng path18.1, không chạy migration tồn đọng khác/reset/fresh. Đã áp dụng thì chỉ báo, không ghi thêm. Script `--apply` chưa thực thi trong phiên này vì DB không truy cập được. Backup và thử phục hồi riêng theo quy trình hiện có; không commit SQL/proof chứa dữ liệu local.

### Điền cấu hình

File duy nhất cần điền là `.env` của Laravel ở `C:/laragon/www/mmtbver2/.env` (đã được Git ignore), xem tên biến trống trong `.env.example`:

```dotenv
DOSSIERS_AI_ENABLED=true
DOSSIERS_AI_PYTHON=C:/Users/Administrator/AppData/Local/Programs/Python/Python312/python.exe
DOSSIERS_AI_ENDPOINT=
DOSSIERS_AI_API_KEY=
DOSSIERS_AI_MODEL=
```

Điền endpoint tương thích Journal hiện có, gồm base path `/v1` nếu dịch vụ yêu cầu (adapter nối `/chat/completions`), API key riêng hồ sơ và model vision do anh chọn trên dịch vụ đó. Không thêm `/chat/completions` vào base URL lần nữa. Không đưa key vào chat/log/Git hoặc dùng biến JOURNAL_* thay thế. Secret chỉ truyền cho tiến trình con qua stdin, không vào argv/job payload/audit. Thiếu một trong endpoint/key/model → **Chưa cấu hình AI**, không gọi API, không giả thành công; nhập tay vẫn hoạt động. Cấu hình đầy đủ chỉ có nghĩa đủ biến, chưa chứng minh kết nối/model/quyền.

Python3.12.10 trên máy này đã có httpx0.28.1/pydantic2.13.5 theo dependency Journal. Kiểm tra interpreter anh điền bằng `python -c "import httpx,pydantic"`. Nếu dùng interpreter khác thì chuẩn bị dependency theo `journal-worker/requirements.txt` trong môi trường riêng; không nâng cấp hàng loạt. Không chạy `python -m mmtb_journal_worker` để thử hồ sơ. Chưa sửa hoặc khởi động worker laptop.

### Khởi động LOCAL

Sau schema và cấu hình, dùng ba terminal riêng tại repo:

```powershell
# Terminal web:
$env:APP_URL='http://127.0.0.1:18180'
$env:ASSET_URL='http://127.0.0.1:18180'
& C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan serve --host=127.0.0.1 --port=18180 --no-reload
# Terminal hồ sơ PDF/thumbnail:
& C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan dossiers:work --watch
# Terminal AI hồ sơ riêng:
& C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan dossiers:ai-work --watch
```

Không mở worker trùng. Nếu đổi config khi terminal AI đang chạy, Ctrl+C chính terminal hồ sơ đó rồi khởi động lại; không dùng queue:restart toàn cục, không restart Journal/Collector/OCR. Không chạy schedule:run toàn bộ để thử. Scheduler đăng ký dossiers:ai-work mỗi phút, giới hạn thời gian giữa jobs; --watch dùng cho LOCAL. Mọi worker AI hồ sơ cùng chịu một DB lease/token fencing: tối đa1request tại một thời điểm, giãn tối thiểu10giây, tối đa3lần/trang hoặc nhóm, backoff30/120giây; không thay quota/fairness Nhật trình.

### Người dùng tự thử trên giao diện

1. Mở `http://127.0.0.1:18180/dossiers`, đăng nhập tài khoản đã được allowlist (chỉ tài khoản được duyệt hiện có, không tự cấp quyền qua đăng ký). Upload PDF mới: sau thumbnail sẵn sàng tự tạo tác vụ AI. Nguồn cũ T-XX0709 đã upload: mở `/dossiers/sources/1`, bấm **Phân tích AI / kiểm tra cấu hình và xếp lại**, không upload lại. Xác nhận đối chiếu máy bằng luồng Phase18 trước khi duyệt giấy.
2. Thiếu config hiện đúng nhãn; sau điền bấm lại để xếp AI. Có thể đóng trang và mở lại/Cập nhật tiến độ; mỗi trang lưu kết quả/usage riêng. Dừng xếp tiếp giữ tác vụ đang chạy và kết quả đã lưu; xếp tiếp hoặc đọc lại riêng trang300dpi. Không gửi lại toàn PDF cho từng trường; trang đọc200dpi/max3300px, reread300dpi/max4500px, nhóm bằng text đã đọc. Pilot tối đa60trang/file và500kbytes context nhóm; khi vượt báo lỗi/nhập tay, không cắt âm thầm. Hệ thống tự dừng xếp thêm sau10tác vụ pilot do upload tự động; người dùng xem rồi bấm Xếp tiếp từng hồ sơ nếu cần. Chỉ tự thử5–10bộ anh chủ động chọn; không xếp cả kho150–200bộ trong giai đoạn pilot.
3. Đề xuất nằm cạnh scan. Click bằng chứng để mở ảnh đọc rõ, đối chiếu quote/trang/vùng; ảnh hiện toàn trang, tọa độ vùng là tham chiếu, chưa crop xuất. Sửa loại, trang/thứ tự/xoay, chọn đúng tài xế/phân công, ngày cấp/kết luận sức khỏe/hiệu lực/hạn/chứng thực. AI không chọn ID người, đổi nhóm Xe/Máy, tạo tài xế hay liên kết sender. BIC cần đối chiếu danh sách/phạm vi thực, không coi mọi người có trong PDF là được bảo hiểm. Trang chứa nhiều giấy độc lập bị chặn áp đề xuất, chuyển xử lý thủ công; không tự nhân đôi cả trang.
4. Quy tắc hạn nghiệp vụ ở link riêng: seed12/24tháng đều chưa bật; tạo phiên bản có scope/căn cứ/ngày hiệu lực khi đủ thông tin. Hạn in trên giấy ưu tiên; hạn tính có nhãn và cần xác nhận phạm vi/ngày gốc. Nếu sửa ngày gốc hoặc loại giấy thì hạn tính cũ bị chặn. Tháng/năm giữ nguyên đến khi xác nhận quy tắc cuối tháng. Ngày cần làm lại/khám lại riêng không làm giấy còn/hết hạn. Không suy hạn đăng ký/chu kỳ đăng kiểm; chưa xác minh pháp lý và dữ liệu xe cần thiết.
5. Đánh dấu đã đối chiếu scan, nhập lý do, **Duyệt thành nháp** hoặc **Xác nhận giấy đã kiểm tra**. Có thể chọn nhiều form đã chỉnh và bấm xác nhận các mục đã chọn; backend vẫn kiểm tra mọi mục atomically. Kết quả cũ hơn sửa tay yêu cầu đối chiếu lại và checkbox riêng. Mục đã áp không áp lần nữa; sửa nháp/thay phiên bản ở Giấy tờ đã lưu. Không có tự xác nhận toàn bộ hay ghi đè bản đã xác nhận.
6. Giấy gia hạn: upload nguồn mới, chỉ nguồn mới được đọc; đối chiếu cùng loại/chủ thể/phạm vi phụ để nối phiên bản qua service cũ. Giấy tương lai chưa thay giấy hiện tại; không bỏ bản cũ. Xuất PDF từ trang máy, chọn đúng các tài xế hiện tại/phiên bản/thứ tự như Phase18; manifest lưu provenance. Xuất Excel dùng cùng bản đã duyệt và engine hạn, bổ sung nguồn hạn/ngày ký kết luận/ngày cần làm lại. Raw AI không tự vào báo cáo nghiệp vụ.

### Bằng chứng và giới hạn hiện tại

Module45tests/511assertions PASS, full613/6136 PASS, Python45tests PASS gồm3bridge mocks. Có mock từ page/group đến duyệt/PDF mở lại/Excel, raw/usage retries, cache, fencing, stale/manual protection, luật tắt và backend Gate. Không gọi API thật, không ghi giấy xác nhận giả vào DB người dùng; không phát sinh chi phí AI. Không sửa bytes T-XX0709, không chạy AI trên file này; T-XX0769/T-3C0624 chưa có acceptance AI. Độ đúng từng trường, thời gian thực/usage/chi phí, MySQL concurrency riêng cho AI/hosting vẫn NOT VERIFIED. Không suy chất lượng AI từ JSON hợp lệ hay confidence. Bảng giá chưa xác minh nên không báo tiền ước đoán; UI chỉ cộng usage API thực sự cung cấp và ghi rõ khi thiếu. Crop xuất và liên kết danh tính tự động chưa có; luồng thủ công được giữ. LOCAL code/mocks PASS không phải DEPLOYED/LIVE VERIFIED.


## Release follow-up30/10 — hosting blocked before deployment

User authorized release with prerequisite+backup gates. See [DOSSIERS-HOSTING-RELEASE.md](DOSSIERS-HOSTING-RELEASE.md) for actual cPanel observations, exact3pending migrations and safe continuation. Hosting currently lacks qpdf/Poppler and Python transport dependencies; deployment/migration/config/cron changes not performed. Release default now AI_ENABLED=false and AUTO_ANALYZE=false. Owner must explicitly enable AI after configuring and choose one dossier; no automatic upload analysis until AUTO_ANALYZE is deliberately enabled later. Previous local enabled=true/auto examples are historical and superseded for release. No AI/Telegram requests. Linux CI now includes dossier tests with environment-supplied binary paths, preserving Windows fallback locally.
