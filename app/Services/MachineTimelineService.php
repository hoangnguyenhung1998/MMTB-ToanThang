<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Machine;
use App\Models\MachineDocument;
use App\Models\MachineDriverHistory;
use App\Models\MachineEvent;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;

class MachineTimelineService
{
    public function build(Machine $machine): Collection
    {
        return collect()
            ->concat($this->activityItems($machine))
            ->concat($this->eventItems($machine))
            ->concat($this->driverItems($machine))
            ->concat($this->documentItems($machine))
            ->push($this->createdItem($machine))
            ->map(function (array $item) {
                $item['occurred_at'] = $this->toCarbon($item['occurred_at'] ?? null);

                return $item;
            })
            ->filter(fn (array $item) => $item['occurred_at'] instanceof Carbon)
            ->unique(fn (array $item) => implode('|', [
                $item['source'] ?? '',
                $item['source_id'] ?? '',
                $item['title'] ?? '',
                $item['occurred_at']->format('Y-m-d H:i:s'),
            ]))
            ->sortByDesc(fn (array $item) => $item['occurred_at']->getTimestamp())
            ->values();
    }

    private function activityItems(Machine $machine): Collection
    {
        return ActivityLog::query()
            ->where('machine_id', $machine->id)
            ->with('user:id,name')
            ->orderByDesc('occurred_at')
            ->get()
            ->map(function (ActivityLog $log) {
                $event = (string) $log->event;

                return [
                    'source' => 'activity',
                    'source_id' => $log->id,
                    'group' => $this->groupFor($event),
                    'title' => $log->eventLabel(),
                    'description' => $log->description ?: $log->eventLabel(),
                    'meta' => $this->activityMeta($log),
                    'actor' => $log->user?->name,
                    'occurred_at' => $log->occurred_at ?: $log->created_at,
                    'tone' => $this->toneFor($event),
                    'icon' => $this->iconFor($event),
                    'search' => implode(' ', array_filter([
                        $log->eventLabel(),
                        $log->description,
                        $log->user?->name,
                    ])),
                ];
            });
    }

    private function eventItems(Machine $machine): Collection
    {
        return MachineEvent::query()
            ->where('machine_id', $machine->id)
            ->with([
                'project:id,name',
                'fromProject:id,name',
                'toProject:id,name',
                'fromCommandCenter:id,name',
                'toCommandCenter:id,name',
            ])
            ->orderByDesc('occurred_at')
            ->get()
            ->map(function (MachineEvent $event) {
                $type = (string) $event->type;
                $from = collect([
                    $event->fromProject?->name,
                    $event->fromCommandCenter?->name,
                ])->filter()->implode(' / ');

                $to = collect([
                    $event->toProject?->name,
                    $event->toCommandCenter?->name,
                ])->filter()->implode(' / ');

                $description = match ($type) {
                    'HANDOVER' => 'Bàn giao thiết bị'.($to ? " đến {$to}." : '.'),
                    'ACTIVATE' => 'Kích hoạt thiết bị để bắt đầu hoạt động.',
                    'TRANSFER' => 'Điều chuyển thiết bị'
                        .($from ? " từ {$from}" : '')
                        .($to ? " đến {$to}" : '')
                        .'.',
                    'RETURN' => 'Trả thiết bị'.($from ? " khỏi {$from}." : '.'),
                    'ASSIGN_DRIVER' => 'Gán tài xế vận hành thiết bị.',
                    'CHANGE_DRIVER' => 'Thay đổi tài xế vận hành thiết bị.',
                    default => 'Cập nhật sự kiện vận hành thiết bị.',
                };

                return [
                    'source' => 'machine_event',
                    'source_id' => $event->id,
                    'group' => $this->groupFor($type),
                    'title' => $this->eventLabel($type),
                    'description' => $description,
                    'meta' => collect([
                        $from ? "Nơi đi: {$from}" : null,
                        $to ? "Nơi đến: {$to}" : null,
                        $event->proof_file_path ? 'Có biên bản đính kèm' : null,
                    ])->filter()->implode(' · '),
                    'actor' => null,
                    'occurred_at' => $event->occurred_at ?: $event->created_at,
                    'tone' => $this->toneFor($type),
                    'icon' => $this->iconFor($type),
                    'search' => implode(' ', array_filter([
                        $this->eventLabel($type),
                        $description,
                        $from,
                        $to,
                    ])),
                ];
            });
    }

    private function driverItems(Machine $machine): Collection
    {
        return MachineDriverHistory::query()
            ->where('machine_id', $machine->id)
            ->with('driver:id,name')
            ->orderByDesc('started_at')
            ->get()
            ->map(function (MachineDriverHistory $history) {
                $driver = $history->driver?->name ?? 'Chưa xác định';
                $endedAt = $this->formatDate($history->ended_at, 'd/m/Y H:i');

                return [
                    'source' => 'driver_history',
                    'source_id' => $history->id,
                    'group' => 'driver',
                    'title' => 'Phân công tài xế',
                    'description' => "Tài xế {$driver} được phân công vận hành thiết bị.",
                    'meta' => $endedAt ? "Kết thúc: {$endedAt}" : 'Đang phụ trách',
                    'actor' => null,
                    'occurred_at' => $history->started_at ?: $history->created_at,
                    'tone' => 'purple',
                    'icon' => 'user',
                    'search' => "Phân công tài xế {$driver}",
                ];
            });
    }

    private function documentItems(Machine $machine): Collection
    {
        return MachineDocument::query()
            ->where('machine_id', $machine->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (MachineDocument $document) {
                $type = $document->doc_type ?: 'Hồ sơ máy';
                $expiry = $this->formatDate($document->expires_at, 'd/m/Y');

                return [
                    'source' => 'machine_document',
                    'source_id' => $document->id,
                    'group' => 'document',
                    'title' => 'Thêm hồ sơ thiết bị',
                    'description' => "Đã thêm hồ sơ: {$type}.",
                    'meta' => collect([
                        $expiry ? "Hết hạn: {$expiry}" : null,
                        $document->file_path ? 'Có file đính kèm' : null,
                    ])->filter()->implode(' · '),
                    'actor' => null,
                    'occurred_at' => $document->created_at,
                    'tone' => 'orange',
                    'icon' => 'document',
                    'search' => "Hồ sơ giấy tờ {$type}",
                ];
            });
    }

    private function createdItem(Machine $machine): array
    {
        return [
            'source' => 'machine',
            'source_id' => $machine->id,
            'group' => 'system',
            'title' => 'Tạo thiết bị',
            'description' => "Thiết bị {$machine->asset_code} được tạo trên hệ thống.",
            'meta' => collect([
                $machine->chassis_no ? "Số khung: {$machine->chassis_no}" : null,
                $machine->company ? "Công ty: {$machine->company}" : null,
            ])->filter()->implode(' · '),
            'actor' => null,
            'occurred_at' => $machine->created_at,
            'tone' => 'blue',
            'icon' => 'plus',
            'search' => "Tạo thiết bị {$machine->asset_code} {$machine->chassis_no}",
        ];
    }

    private function eventLabel(string $event): string
    {
        return match ($event) {
            'HANDOVER' => 'Bàn giao',
            'ACTIVATE' => 'Kích hoạt',
            'TRANSFER' => 'Điều chuyển',
            'RETURN' => 'Trả máy',
            'ASSIGN_DRIVER' => 'Gán tài xế',
            'CHANGE_DRIVER' => 'Đổi tài xế',
            default => str($event)->replace('_', ' ')->title()->toString(),
        };
    }

    private function groupFor(string $event): string
    {
        $event = mb_strtolower($event);

        return match (true) {
            str_contains($event, 'document') => 'document',
            str_contains($event, 'driver') => 'driver',
            str_contains($event, 'handover') => 'handover',
            str_contains($event, 'transfer') => 'transfer',
            str_contains($event, 'return') => 'return',
            str_contains($event, 'activate') => 'status',
            default => 'system',
        };
    }

    private function toneFor(string $event): string
    {
        $event = mb_strtolower($event);

        return match (true) {
            str_contains($event, 'return') || str_contains($event, 'deleted') => 'red',
            str_contains($event, 'document') => 'orange',
            str_contains($event, 'driver') => 'purple',
            str_contains($event, 'transfer') => 'yellow',
            str_contains($event, 'activate') => 'green',
            default => 'blue',
        };
    }

    private function iconFor(string $event): string
    {
        $event = mb_strtolower($event);

        return match (true) {
            str_contains($event, 'return') => 'return',
            str_contains($event, 'document') => 'document',
            str_contains($event, 'driver') => 'user',
            str_contains($event, 'transfer') => 'transfer',
            str_contains($event, 'activate') => 'play',
            str_contains($event, 'handover') => 'handover',
            default => 'dot',
        };
    }

    private function activityMeta(ActivityLog $log): ?string
    {
        $changes = data_get($log->properties, 'changes', []);

        return is_array($changes) && count($changes) > 0
            ? count($changes).' trường dữ liệu thay đổi'
            : null;
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        if (is_string($value)) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function formatDate(mixed $value, string $format): ?string
    {
        return $this->toCarbon($value)?->format($format);
    }

}
