<?php

namespace App\Services;

use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ZaloIngestionService
{
    public function __construct(private readonly OcrJobService $ocrJobs)
    {
    }

    public function ingest(array $data, UploadedFile $file): ZaloIngestionResult
    {
        $actualHash = hash_file('sha256', $file->getRealPath());

        if (! hash_equals($data['sha256'], $actualHash)) {
            throw ValidationException::withMessages([
                'sha256' => 'The uploaded file does not match the supplied SHA-256 hash.',
            ]);
        }

        $disk = (string) config('collector.disk');
        $storedPath = null;

        try {
            return DB::transaction(function () use ($data, $file, $actualHash, $disk, &$storedPath): ZaloIngestionResult {
                $message = ZaloMessage::query()->firstOrCreate(
                    [
                        'group_id' => $data['group_id'],
                        'message_id' => $data['message_id'],
                    ],
                    [
                        'sender_id' => $data['sender_id'] ?? null,
                        'sender_name' => $data['sender_name'] ?? null,
                        'sent_at' => $data['sent_at'],
                        'received_at' => now(),
                        'raw_payload' => isset($data['raw_payload'])
                            ? json_decode($data['raw_payload'], true, flags: JSON_THROW_ON_ERROR)
                            : null,
                        'status' => 'RECEIVED',
                    ],
                );

                $existingAttachment = $message->attachments()
                    ->where('attachment_index', $data['attachment_index'])
                    ->first();

                if ($existingAttachment) {
                    return new ZaloIngestionResult(
                        $existingAttachment->load('message'),
                        false,
                    );
                }

                $duplicate = ZaloAttachment::query()
                    ->where('sha256', $actualHash)
                    ->whereIn('status', ['STORED', 'DUPLICATE'])
                    ->oldest('id')
                    ->first();

                if ($duplicate) {
                    $storagePath = $duplicate->storage_path;
                    $status = 'DUPLICATE';
                } else {
                    $storagePath = $this->storeFile($file, $actualHash, $data['sent_at']);
                    $storedPath = $storagePath;
                    $status = 'STORED';
                }

                $storageDisk = $duplicate?->storage_disk ?? $disk;

                $attachment = $message->attachments()->create([
                    'attachment_index' => $data['attachment_index'],
                    'original_name' => $file->getClientOriginalName(),
                    'storage_disk' => $storageDisk,
                    'storage_path' => $storagePath,
                    'sha256' => $actualHash,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'byte_size' => $file->getSize(),
                    'status' => $status,
                    'duplicate_of_attachment_id' => $duplicate?->id,
                ]);

                if ($status === 'STORED') {
                    $this->ocrJobs->enqueue($attachment->id);
                }

                $message->update(['status' => 'STORED']);

                return new ZaloIngestionResult($attachment->load('message'), true);
            }, 3);
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk($disk)->delete($storedPath);
            }

            throw $exception;
        }
    }

    private function storeFile(UploadedFile $file, string $hash, string $sentAt): string
    {
        $date = date_create_immutable($sentAt);
        $directory = trim((string) config('collector.directory'), '/').'/'.$date->format('Y/m/d');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = $directory.'/'.$hash.'.'.$extension;

        Storage::disk((string) config('collector.disk'))->putFileAs(
            $directory,
            $file,
            basename($path),
        );

        return $path;
    }
}
