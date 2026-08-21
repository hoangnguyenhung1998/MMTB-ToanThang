<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\OcrJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class OcrReviewService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return OcrJob::query()
            ->with([
                'machine:id,asset_code',
                'attachment:id,zalo_message_id,original_name,mime_type',
                'attachment.message:id,message_id,sender_name,sent_at',
            ])
            ->when($filters['q'] ?? null, function (Builder $query, string $value): void {
                $search = '%'.trim($value).'%';
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('asset_code', 'like', $search)
                        ->orWhereHas('attachment.message', function (Builder $message) use ($search): void {
                            $message->where('message_id', 'like', $search)
                                ->orWhere('sender_name', 'like', $search);
                        });
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['document_type'] ?? null, fn (Builder $query, string $type) => $query->where('document_type', $type))
            ->when($filters['machine_id'] ?? null, fn (Builder $query, int|string $machineId) => $query->where('machine_id', $machineId))
            ->when($filters['date_from'] ?? null, function (Builder $query, string $date): void {
                $query->whereHas('attachment.message', fn (Builder $message) => $message->whereDate('sent_at', '>=', $date));
            })
            ->when($filters['date_to'] ?? null, function (Builder $query, string $date): void {
                $query->whereHas('attachment.message', fn (Builder $message) => $message->whereDate('sent_at', '<=', $date));
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
    }

    public function statusCounts(): Collection
    {
        return OcrJob::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
    }

    public function machineOptions(): Collection
    {
        return Machine::query()->orderBy('asset_code')->get(['id', 'asset_code']);
    }

    public function detail(OcrJob $job): OcrJob
    {
        return $job->load([
            'machine:id,asset_code,chassis_no,plate_no,machine_type',
            'attachment.message',
            'journalDocument.rows',
        ]);
    }

    public function imageExists(OcrJob $job): bool
    {
        $attachment = $job->attachment;

        return $attachment !== null
            && Storage::disk($attachment->storage_disk)->exists($attachment->storage_path);
    }

    public function exceptionLabels(): array
    {
        return [
            'LOW_CONFIDENCE' => 'Độ tin cậy thấp',
            'MISSING_DATE' => 'Thiếu ngày làm việc',
            'MISSING_TIME' => 'Thiếu giờ chụp',
            'UNCLASSIFIED_TIME' => 'Không xác định được ca làm việc',
            'MISSING_ASSET_CODE' => 'Thiếu mã thiết bị',
            'UNKNOWN_ASSET_CODE' => 'Mã thiết bị không tồn tại',
            'WRONG_DATE' => 'Ngày ảnh không phù hợp ngày gửi',
            'MISSING_WORK_CONTENT' => 'Thiếu nội dung công việc',
            'JOURNAL_ROW_EXCEPTION' => 'Có dòng nhật trình cần kiểm tra',
            'UNCLASSIFIED_DOCUMENT' => 'Không phân loại được tài liệu',
        ];
    }
}
