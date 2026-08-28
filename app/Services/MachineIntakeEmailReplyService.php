<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\MachineIntakeCase;
use App\Models\MachineIntakeEmailReply;
use App\Models\MachineIntakeEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MachineIntakeEmailReplyService
{
    public function __construct(
        private readonly MachineIntakeAlertDispatcher $alerts,
        private readonly MachineIntakeService $intakes,
    ) {}

    public function ingest(array $data): MachineIntakeEmailReply
    {
        $existing = MachineIntakeEmailReply::where('gmail_message_id', $data['gmail_message_id'])->first();
        if ($existing) return $existing;

        [$case, $method, $ambiguous] = $this->matchCase($data);
        $code = $this->normalizeCode($data['candidate_asset_code'] ?? null);
        $confidence = isset($data['confidence']) ? (float) $data['confidence'] : null;
        $minimum = (float) config('gmail_intake.minimum_confidence', 0.85);
        $status = $ambiguous ? 'AMBIGUOUS_CASE' : (! $case ? 'UNMATCHED' : (! $code ? 'NO_CODE' : ($confidence !== null && $confidence < $minimum ? 'LOW_CONFIDENCE' : 'PENDING')));
        [$disk, $path] = $this->storeEvidence($case, $data);

        $reply = DB::transaction(function () use ($data, $case, $method, $code, $confidence, $status, $disk, $path) {
            $reply = MachineIntakeEmailReply::create([
                'machine_intake_case_id' => $case?->id,
                'gmail_message_id' => $data['gmail_message_id'],
                'gmail_thread_id' => $data['gmail_thread_id'] ?? null,
                'sender' => $data['sender'] ?? null,
                'subject' => $data['subject'] ?? null,
                'body_text' => $data['body_text'] ?? null,
                'received_at' => $data['received_at'] ?? now(),
                'candidate_asset_code' => $code,
                'confidence' => $confidence,
                'match_method' => $method,
                'status' => $status,
                'evidence_disk' => $disk,
                'evidence_path' => $path,
                'metadata' => $data['metadata'] ?? null,
            ]);
            if ($case) {
                if (! $case->email_thread_id && ! empty($data['gmail_thread_id'])) {
                    $case->update(['email_thread_id' => $data['gmail_thread_id']]);
                }
                MachineIntakeEvent::create([
                    'machine_intake_case_id' => $case->id,
                    'event' => 'intake.gmail_reply_received',
                    'properties' => ['reply_id' => $reply->id, 'candidate_asset_code' => $code, 'confidence' => $confidence, 'status' => $status, 'match_method' => $method],
                    'occurred_at' => now(),
                ]);
            }
            return $reply;
        });

        if ($reply->status === 'PENDING') {
            $error = $this->alerts->codeCandidate($reply->load('intakeCase'));
            if ($error) $reply->update(['metadata' => array_merge($reply->metadata ?? [], ['telegram_error' => $error])]);
        }
        return $reply->fresh('intakeCase');
    }

    public function confirm(MachineIntakeEmailReply $reply, User $user): MachineIntakeCase
    {
        if ($reply->status !== 'PENDING' || ! $reply->intakeCase || ! $reply->candidate_asset_code) {
            throw new BusinessRuleException('Email này chưa có mã hợp lệ hoặc chưa ghép được với hồ sơ.');
        }
        $case = $this->intakes->assignAssetCode($reply->intakeCase, [
            'asset_code' => $reply->candidate_asset_code,
            'asset_code_source' => 'EMAIL_REPLY',
            'asset_code_source_note' => 'Gmail: '.$reply->gmail_message_id.' · '.$reply->sender,
        ], $user, null, $reply->evidence_path);
        $reply->update(['status' => 'CONFIRMED', 'confirmed_by' => $user->id, 'confirmed_at' => now()]);
        return $case;
    }

    private function matchCase(array $data): array
    {
        $waiting = MachineIntakeCase::query()->whereIn('status', ['EMAIL_SENT', 'WAIT_ASSET_CODE'])->get();
        $thread = trim((string) ($data['gmail_thread_id'] ?? ''));
        if ($thread !== '') {
            $matched = $waiting->filter(fn ($case) => $case->email_thread_id && hash_equals((string) $case->email_thread_id, $thread));
            if ($matched->count() === 1) return [$matched->first(), 'GMAIL_THREAD', false];
        }

        $haystack = (string) ($data['subject'] ?? '')."\n".(string) ($data['body_text'] ?? '');
        preg_match_all('/TN-\d{4}-\d{6}/i', $haystack, $references);
        $referenceMatches = $waiting->whereIn('reference', array_map('strtoupper', $references[0] ?? []));
        if ($referenceMatches->count() === 1) return [$referenceMatches->first(), 'REFERENCE', false];
        if ($referenceMatches->count() > 1) return [null, 'REFERENCE', true];

        $normalizedText = $this->normalizeComparable($haystack);
        $identifierMatches = $waiting->filter(function ($case) use ($normalizedText) {
            foreach ([$case->chassis_no, $case->engine_no] as $identifier) {
                $value = $this->normalizeComparable((string) $identifier);
                if ($value !== '' && str_contains($normalizedText, $value)) return true;
            }
            return false;
        });
        if ($identifierMatches->count() === 1) return [$identifierMatches->first(), 'IDENTIFIER', false];
        return [null, $identifierMatches->count() > 1 ? 'IDENTIFIER' : null, $identifierMatches->count() > 1];
    }

    private function normalizeCode(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        $value = preg_replace('/\s+/', '', $value);
        if ($value === '' || ! preg_match('/^(?:SGC-)?(?:VT|T)-[A-Z0-9-]{4,20}$/', $value)) return null;
        return $value;
    }

    private function normalizeComparable(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $value));
    }

    private function storeEvidence(?MachineIntakeCase $case, array $data): array
    {
        if (empty($data['evidence_base64'])) return [null, null];
        $binary = base64_decode($data['evidence_base64'], true);
        if ($binary === false) return [null, null];
        $name = basename((string) ($data['evidence_name'] ?? 'gmail-evidence.bin'));
        $folder = 'machine-intakes/'.($case?->reference ?? 'unmatched').'/gmail-replies';
        $path = $folder.'/'.hash('sha256', $binary).'-'.$name;
        Storage::disk('public')->put($path, $binary);
        return ['public', $path];
    }
}
