# RUNBOOKS

Runbook lưu các quy trình được sử dụng lặp lại.

Ví dụ:

- quản lý Phase;
- deploy production;
- rollback;
- restart Collector/worker;
- incident response;
- reconciliation.

Phase trả lời: **đã thay đổi gì và tại sao**.

Runbook trả lời: **thực hiện quy trình đó như thế nào**.

Không copy toàn bộ runbook vào từng Phase; Phase chỉ tham chiếu runbook và ghi kết quả thực tế.

- [Daily Photo background recovery / legacy downstream diagnostic](DAILY-PHOTO-BACKGROUND-RECOVERY.md) — Phase17.4 minute worker, progress/retry, approved release and isolated acceptance.

- [Bulk AI Rescue background requests](AI-RESCUE-BACKGROUND-BULK.md) — Phase17.5 selection snapshot, confirmation, enqueue/OCR progress, retry and isolated tests.
