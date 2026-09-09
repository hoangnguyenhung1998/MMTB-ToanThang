# RUNBOOK — DEPLOYMENT

> Template. Phải điều chỉnh theo deployment thực tế của MMTB trước khi sử dụng như quy trình production chính thức.

## Pre-deploy

- [ ] Xác nhận Phase/commit cần deploy.
- [ ] Working tree phù hợp.
- [ ] Test bắt buộc PASS.
- [ ] Migration/config thay đổi đã được rà soát.
- [ ] Có phương án rollback nếu thay đổi rủi ro.

## Deploy

Ghi lệnh/quy trình deployment thực tế của dự án tại đây.

Không tự suy đoán lệnh production nếu runbook chưa được xác minh.

## Post-deploy

- [ ] Kiểm tra ứng dụng hoạt động.
- [ ] Kiểm tra migration nếu có.
- [ ] Kiểm tra worker/service liên quan.
- [ ] Chạy production verification của Phase.
- [ ] Cập nhật `PROJECT_STATE.md`.
- [ ] Cập nhật file Phase.

## Rollback

Ghi quy trình rollback đã được xác minh tại đây.
