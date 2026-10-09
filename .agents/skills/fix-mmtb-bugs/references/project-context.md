# Môi trường đã xác minh 09/10/2026

Dùng làm điểm xuất phát; repo docs/runtime mới nhất quyết định trạng thái hiện tại.

- Repo: hoangnguyenhung1998/MMTB-ToanThang; branch release production.
- PC công ty: C:\laragon\www\mmtbver2.
- PC nhà: D:\laragon\www\MMTB-ToanThang.
- Hosting: /home/mmzoxgme/repositories/MMTB-ToanThang; user mmzoxgme.
- PHP hosting: /opt/alt/php83/usr/bin/php; log đã thấy 8.3.35.
- public/.htaccess có chỉnh riêng và runtime untracked: bảo toàn.
- Cron mỗi phút cd repo rồi PHP artisan schedule:run, append storage/logs/scheduler.log.
- Repair command reconciliation:process-repair-runs --limit=1 xử lý một kỳ/invocation. schedule:list chưa chứng minh execution.

## Kiểm tra

```bash
cd /home/mmzoxgme/repositories/MMTB-ToanThang
git status --short
git branch --show-current
git fetch origin
git log -1 --oneline
git log -1 --oneline origin/production
```

Sau khi review exact release và được phép deploy, pull fast-forward; dừng khi lỗi:

```bash
git pull --ff-only origin production
git log -1 --oneline
/opt/alt/php83/usr/bin/php artisan view:clear
/opt/alt/php83/usr/bin/php artisan route:clear
/opt/alt/php83/usr/bin/php artisan migrate:status
/opt/alt/php83/usr/bin/php artisan schedule:list
```

Chỉ migrate additive đúng path khi cần và được phép. Không optimize:clear mặc định vì có thể clear cache/locks. Không chạy schedule:run thủ công khi chưa review các tác vụ khác. Không mặc định restart Collector/OCR. Đọc log có lọc, không yêu cầu raw secret/SQL. Nếu không có access, hướng dẫn user, không claim đã chạy.
