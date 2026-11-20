<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierWorkerJob extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['input' => 'array', 'result' => 'array', 'attempts' => 'integer', 'attempt_history' => 'array', 'queued_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'claimed_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime'];

    public function finishedAttempt(string $outcome, ?string $error = null): array
    {
        if (! $this->claimed_at) {
            return $this->attempt_history ?? [];
        }

        return [...($this->attempt_history ?? []), [
            'started_at' => $this->claimed_at->toIso8601String(),
            'finished_at' => ($outcome === 'expired' ? $this->updated_at : now())->toIso8601String(),
            'queued_at' => ($this->queued_at ?? $this->created_at)->toIso8601String(),
            'outcome' => $outcome, 'error' => $error,
        ]];
    }
}
