---
name: deployment
description: Dùng khi chuẩn bị commit, đồng bộ hai PC development, push GitHub, cập nhật laptop 24/24, deploy Laravel lên hosting, migration, clear cache, restart worker hoặc kiểm tra release.
---

# Skill: Deployment MMTB

## Phạm vi

Dùng cho:

- Git synchronization.
- Chuẩn bị commit.
- Push/PR/merge planning.
- Chuyển phiên làm việc giữa PC công ty và PC nhà.
- Cập nhật laptop runtime.
- Hosting deployment.
- Laravel migration.
- Cache clear/optimization.
- Worker restart.
- Post-deploy verification.

Skill này KHÔNG tự cấp quyền deploy.

Các hành động remote/production vẫn phải tuân thủ yêu cầu phê duyệt trong `AGENTS.md`.

---

## Workflow development chuẩn

1. Đồng bộ máy development.
2. Codex sửa local.
3. Chạy targeted tests.
4. Sửa regression.
5. Người dùng test nghiệp vụ local khi cần.
6. Review diff.
7. Commit.
8. Push GitHub sau khi được phê duyệt.
9. Chỉ deploy code đã được xác nhận.

GitHub là source of truth.

---

## Hai PC development

PC công ty và PC nhà đều có thể code/test.

Đầu phiên:

git status
git branch --show-current
git fetch origin

Sau đó xác minh branch/upstream và chênh lệch local/remote.

Chỉ chạy git pull khi:
- working tree an toàn;
- đúng branch;
- upstream đúng;
- việc pull không ghi đè hoặc trộn thay đổi local ngoài ý muốn.

Không pull đè lên uncommitted local changes.

Cuối phiên thành công:

```bash
git status
git diff
```

Sau đó commit/push theo quyền được phê duyệt.

Trước khi tiếp tục ở máy development còn lại, phải sync code mới nhất.

---

## Branch

Với thay đổi nhỏ/an toàn, có thể theo branch policy hiện hành của project.

Với feature lớn hoặc thay đổi rủi ro, ưu tiên:

```text
feature/<short-name>
fix/<short-name>
```

Không tạo branching process phức tạp nếu không mang lại lợi ích thực tế.

Không force-push shared branch nếu chưa được yêu cầu.

---

## Deploy Laravel production

Quy trình production thường có thể gồm:

```bash
git pull
```

Nếu có migration:

```bash
php artisan migrate --force
```

Sau đó thường:

```bash
php artisan optimize:clear
```

Hosting có thể sử dụng PHP binary khác local.

Dùng đúng PHP command đã được thiết lập trên hosting.

Không chạy production command khi chưa được yêu cầu.

---

## Cập nhật laptop 24/24

Khi Collector/worker thay đổi:

1. Xác nhận local tests PASS.
2. Xác nhận GitHub có commit đã duyệt.
3. Update runtime repo có kiểm soát.
4. Restart chỉ process bị ảnh hưởng.
5. Kiểm tra log.
6. Kiểm tra kết nối Laravel.
7. Xác nhận pending job/message không bị mất.
8. Xác nhận không xử lý duplicate.

Không restart worker không liên quan.

---

## Thứ tự migration/deploy

Nếu code và migration phụ thuộc thứ tự:

- xác định safe deployment order trước;
- ưu tiên migration backward-compatible;
- cân nhắc rollback.

Báo rõ nếu deployment cần downtime hoặc strict ordering.

---

## Kiểm tra sau deploy

### Laravel

Kiểm tra tối thiểu:

- application phản hồi;
- login nếu liên quan;
- page/API vừa sửa hoạt động;
- không có lỗi 500 rõ ràng;
- queue/job path liên quan vẫn hoạt động.

### Collector

Kiểm tra:

- active account đúng;
- group collection đúng;
- ảnh/tin nhắn tới Laravel;
- không duplicate bất thường.

### Workers

Kiểm tra:

- process đang chạy;
- claim/process được job;
- log không lặp lỗi;
- final status đúng.

---

## Rollback

Trước deployment rủi ro, xác định rollback có thể bao gồm:

- rollback code;
- rollback worker;
- migration compatibility;
- config rollback.

Không tự rollback production nếu chưa được yêu cầu.

---

## Hoàn thành khi chuẩn bị deploy

Báo rõ:

- Commit/branch dự kiến deploy.
- Tests đã chạy.
- Có migration hay không.
- Laravel command cần chạy.
- Có cần update laptop hay không.
- Có cần restart worker hay không.
- Manual production checks.
- Rủi ro rollback nếu có.
