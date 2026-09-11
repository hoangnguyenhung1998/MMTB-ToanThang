<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\OcrJob;

class DailyPhotoMachineResolutionService
{
    public const IMAGE_ASSET = 'IMAGE_ASSET';

    public const SENDER_DRIVER_HISTORY = 'SENDER_DRIVER_HISTORY';

    public const HUMAN = 'HUMAN';

    public const SENDER_MAPPING = 'SENDER_MAPPING';

    public function __construct(
        private readonly ZaloSenderDriverService $senderResolver,
        private readonly AssetCodeResolver $assetCodes,
    ) {}

    public function resolve(OcrJob $job, mixed $assetCode, ?string $date, ?string $time, bool $learn = false): array
    {
        $asset = $this->assetCodes->resolve($assetCode);
        $observed = $asset['observed'];
        $imageMachine = $asset['machine'];
        $sender = $this->senderResolver->resolveWithProvenance($job, $date, $time);
        $senderMachine = $sender['machine'];

        $mappingService = app(ZaloSenderMachineService::class);
        $candidates = $mappingService->receiptCandidates($job);
        $mapping = $candidates->count() === 1 ? $candidates->first() : null;
        if ($candidates->count() > 1) {
            $senderMachine = null;
        }
        if ($mapping) {
            $senderMachine = $mapping->machine;
        }
        if ($asset['status'] === 'AMBIGUOUS') {
            $senderMachine = null;
            $mapping = null;
        }
        // Persisted evidence resolution survives OCR retries and later mapping edits.
        $frozen = $job->machine_resolution_method && $job->machine_id
            ? Machine::find($job->machine_id) : null;
        if ($frozen && ($job->machine_resolution_method === self::HUMAN || ! $imageMachine)) {
            return [
                'observed_asset_code' => $observed ?? $job->observed_asset_code,
                'legacy_asset_code' => $job->asset_code,
                'image_machine' => $imageMachine, 'machine' => $frozen,
                'asset_resolution_status' => $asset['status'],
                'method' => $job->machine_resolution_method,
                'sender_driver_link_id' => $job->sender_driver_link_id,
                'machine_driver_history_id' => $job->machine_driver_history_id,
                'metadata' => $job->machine_resolution_metadata,
            ];
        }
        if ($learn && $imageMachine) {
            $mappingService->learn($job, $senderMachine ?: $imageMachine);
        }

        $machine = $imageMachine ?: $senderMachine;
        $method = $imageMachine
            ? self::IMAGE_ASSET
            : ($senderMachine ? ($mapping ? self::SENDER_MAPPING : self::SENDER_DRIVER_HISTORY) : null);
        $usedSender = $method === self::SENDER_DRIVER_HISTORY;

        return [
            'observed_asset_code' => $observed,
            'legacy_asset_code' => $observed ?: $senderMachine?->asset_code,
            'image_machine' => $imageMachine,
            'machine' => $machine,
            'asset_resolution_status' => $asset['status'],
            'method' => $method,
            'sender_driver_link_id' => $usedSender ? $sender['link']?->id : null,
            'machine_driver_history_id' => $usedSender ? $sender['history']?->id : null,
            'metadata' => [
                'version' => config('daily_photos.foundation_version'),
                'capture_datetime_convention' => 'NAIVE_LOCAL_WALL_CLOCK',
                'capture_timezone' => config('daily_photos.capture_timezone'),
                'sender_machine_mapping_id' => $mapping?->id,
                'image_asset_resolved_machine_id' => $imageMachine?->id,
                'observed_asset_normalized_key' => $asset['normalized_key'],
                'image_asset_resolution_status' => $asset['status'],
                'image_asset_candidate_machine_ids' => $asset['candidate_machine_ids'],
                'image_asset_candidate_codes' => $asset['candidate_asset_codes'],
                'sender_resolution_status' => $candidates->count() > 1 ? 'AMBIGUOUS_MAPPING' : ($mapping ? 'RESOLVED' : $sender['status']),
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
}
