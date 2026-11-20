# Reset danh mục tài xế Phase 18.5

Thực hiện thủ công sau khi PR đã merge và CI đã được xác minh. Lấy `RELEASE_SHA` từ commit production đã duyệt trên GitHub. Dừng nếu checkout không khớp SHA, đang có job hồ sơ chạy hoặc phát hiện khóa ngoại chưa rà.

1. Xác minh document root, checkout ứng dụng, `.env` riêng, `APP_ENV` production, `SELECT DATABASE(), @@hostname, @@port` qua kết nối Laravel và danh sách `migrate:status`. Dùng PHP CLI của hosting. Chuyển web vào chế độ bảo trì đã duyệt, ngừng nhận ghi liên quan và đợi job hồ sơ đang chạy kết thúc. Giữ cấu hình và bytes nguồn riêng.
2. Lấy dump liên tục nhất quán bằng `mariadb-dump --single-transaction --quick --no-tablespaces --hex-blob`. Dùng file cấu hình client riêng quyền 0600, truyền credential qua cơ chế hosting, giữ dump ngoài webroot. Kiểm kích thước, dấu kết thúc và SHA256. Thử restore vào môi trường cách ly được phép và đối chiếu số máy, tài xế, hồ sơ, nguồn và export. Không restore đè database ứng dụng.
3. Dùng checkout đúng SHA và danh sách migration đã review. Chạy riêng migration Phase 18.5 nếu 18.4 đã áp. Dừng nếu còn migration ngoài phạm vi. Dùng số liệu preview sau migrate trên DB hosting để kiểm phạm vi và chốt `PREVIEW_TOKEN`. Số liệu LOCAL chỉ là bằng chứng nghiệm thu. Lệnh `--database` phải là tên SELECT DATABASE trả về.

```sh
set -eu
test "$(git rev-parse HEAD)" = "$RELEASE_SHA" || exit 1
"$PHP" artisan migrate:status --no-ansi
"$PHP" artisan migrate --force --path=database/migrations/2026_11_20_000003_prepare_zalo_driver_reset.php
"$PHP" artisan dossiers:reset-drivers --database="$DB_NAME"
```

Sau khi đối chiếu preview, điền `PREVIEW_TOKEN`, `BACKUP_SHA256` đã xác minh và ID người thao tác. Chạy apply xong mới seed.

```sh
set -eu
test "$(git rev-parse HEAD)" = "$RELEASE_SHA" || exit 1
sha256sum "$BACKUP_FILE"
"$PHP" artisan dossiers:reset-drivers --database="$DB_NAME" --apply="$PREVIEW_TOKEN" --backup="$BACKUP_FILE" --sha256="$BACKUP_SHA256"
"$PHP" artisan dossiers:reset-drivers --database="$DB_NAME" --seed --actor="$AUTHORIZED_USER_ID"
```

4. Kiểm sổ báo reset hoàn tất và đối chiếu khóa ngoại. Xác minh máy, nhật ký, ảnh gốc, phiên bản máy và nguồn hỗn hợp còn nguyên. Kiểm số Driver theo sender hiện hành, một sender một Driver. Kiểm `/drivers`, Settings, chọn chủ thể, file export cá nhân cũ trả 404 và tạo bộ PDF mới. Cho web hoạt động lại sau kiểm tra. Nếu seed gián đoạn thì chạy riêng `--seed`; ledger ngăn reset lần hai.

Lệnh chỉ gỡ quyền truy cập bản xuất cá nhân cũ và nguồn riêng đã xác định. File hỗn hợp, file chưa đủ căn cứ và bytes lưu trữ riêng được giữ. Dọn bytes cần kiểm kê khóa lưu và toàn bộ tham chiếu độc lập với reset DB. Cache OCR cũ được xóa vì có thể chứa đoạn cá nhân chưa phân loại; xác nhận giấy máy và file của chúng vẫn giữ. Khôi phục dữ liệu đã xóa dùng backup đã xác minh và quy trình restore riêng.
