<?php

namespace App\Services\Dossiers;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class DossierAudit
{
    public static function write(string $event, Model $model, int $actor, array $before = []): void
    {
        $after = $model->fresh()->toArray();
        // Audit records identifiers, decisions and state, while the private dossier
        // retains raw OCR and identity evidence.
        if ($model->getTable() === 'dossier_ai_proposals' && str_starts_with($event, 'ai_classification_')) {
            $after['classification'] = array_intersect_key($after['data'], array_flip(['type_id', 'pages']));
        }
        switch ($model->getTable()) {
            case 'dossier_ai_pages':
            case 'dossier_ai_runs':
            case 'dossier_ai_proposals':
                foreach (['raw', 'result', 'group_result', 'data', 'reviewed', 'issues'] as $key) {
                    unset($before[$key], $after[$key]);
                }
                break;
            case 'dossier_versions':
                foreach (['verified_identity', 'insurance_evidence', 'evidence', 'insured', 'subject_claim', 'scope_text'] as $key) {
                    unset($before['provenance'][$key], $after['provenance'][$key]);
                }
                if ($model->record?->type?->ai_key === 'identity') {
                    unset($before['number'], $after['number']);
                }
                break;
        }
        ActivityLog::create(['user_id' => $actor, 'event' => 'dossier.'.$event,
            'description' => 'Hồ sơ điện tử: '.$event, 'properties' => ['entity' => $model->getTable(),
                'id' => $model->getKey(), 'before' => $before, 'after' => $after], 'occurred_at' => now()]);
    }
}
