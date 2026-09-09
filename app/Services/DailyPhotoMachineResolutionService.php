<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\OcrJob;

class DailyPhotoMachineResolutionService
{
    public const IMAGE_ASSET = 'IMAGE_ASSET';

    public const SENDER_DRIVER_HISTORY = 'SENDER_DRIVER_HISTORY';

    public const HUMAN = 'HUMAN';

    public function __construct(private readonly ZaloSenderDriverService $senderResolver) {}

    public function resolve(OcrJob $job, mixed $assetCode, ?string $date, ?string $time): array
    {
        $observed = $this->normalizeAssetCode($assetCode);
        $imageMachine = $observed
            ? Machine::query()->where('asset_code', $observed)->first()
            : null;
        $sender = $this->senderResolver->resolveWithProvenance($job, $date, $time);
        $senderMachine = $sender['machine'];

        $machine = $imageMachine ?: $senderMachine;
        $method = $imageMachine
            ? self::IMAGE_ASSET
            : ($senderMachine ? self::SENDER_DRIVER_HISTORY : null);
        $usedSender = $method === self::SENDER_DRIVER_HISTORY;

        return [
            'observed_asset_code' => $observed,
            'legacy_asset_code' => $observed ?: $senderMachine?->asset_code,
            'image_machine' => $imageMachine,
            'machine' => $machine,
            'method' => $method,
            'sender_driver_link_id' => $usedSender ? $sender['link']?->id : null,
            'machine_driver_history_id' => $usedSender ? $sender['history']?->id : null,
            'metadata' => [
                'version' => config('daily_photos.foundation_version'),
                'capture_datetime_convention' => 'NAIVE_LOCAL_WALL_CLOCK',
                'capture_timezone' => config('daily_photos.capture_timezone'),
                'image_asset_resolved_machine_id' => $imageMachine?->id,
                'sender_resolution_status' => $sender['status'],
                'sender_resolution_machine_id' => $senderMachine?->id,
                'sender_driver_link_id' => $sender['link']?->id,
                'machine_driver_history_id' => $sender['history']?->id,
                'sender_resolution_mismatch' => (bool) ($imageMachine && $senderMachine && $imageMachine->id !== $senderMachine->id),
                'sender_candidate_link_ids' => $sender['candidate_link_ids'],
                'sender_candidate_history_ids' => $sender['candidate_history_ids'],
                'sender_candidate_machine_ids' => $sender['candidate_machine_ids'],
            ],
        ];
    }

    private function normalizeAssetCode(mixed $assetCode): ?string
    {
        if ($assetCode === null) {
            return null;
        }
        $normalized = strtoupper(trim((string) $assetCode));

        return $normalized === '' ? null : $normalized;
    }
}
