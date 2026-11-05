<?php

namespace App\Services\Dossiers;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class DossierAudit
{
    public static function write(string $event, Model $model, int $actor, array $before = []): void
    {
        ActivityLog::create(['user_id' => $actor, 'event' => 'dossier.'.$event,
            'description' => 'Hồ sơ điện tử: '.$event, 'properties' => ['entity' => $model->getTable(),
                'id' => $model->getKey(), 'before' => $before, 'after' => $model->fresh()->toArray()], 'occurred_at' => now()]);
    }
}
