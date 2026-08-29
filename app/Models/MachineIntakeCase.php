<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MachineIntakeCase extends Model
{
    public const STATUSES = ['NEW', 'EXTRACTED', 'CONFIRMED', 'EMAIL_SENT', 'WAIT_ASSET_CODE', 'CODE_RECEIVED', 'WAIT_HANDOVER', 'DUPLICATE'];
    public const CODE_SOURCES = ['EMAIL_REPLY', 'ZALO_BCH', 'PHONE', 'EXCEL', 'OTHER'];

    protected $guarded = [];

    protected $casts = [
        'email_sent_at' => 'datetime', 'code_received_at' => 'datetime',
        'handover_at' => 'datetime', 'confirmed_at' => 'datetime',
        'closed_at' => 'datetime',
        'extraction_summary' => 'array', 'review_flags' => 'array',
    ];

    public function machine(): BelongsTo { return $this->belongsTo(Machine::class); }
    public function duplicateMachine(): BelongsTo { return $this->belongsTo(Machine::class, 'duplicate_machine_id'); }
    public function closer(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function commandCenter(): BelongsTo { return $this->belongsTo(CommandCenter::class); }
    public function driver(): BelongsTo { return $this->belongsTo(Driver::class); }
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by'); }
    public function documents(): HasMany { return $this->hasMany(MachineIntakeDocument::class); }
    public function emailReplies(): HasMany { return $this->hasMany(MachineIntakeEmailReply::class); }
    public function events(): HasMany { return $this->hasMany(MachineIntakeEvent::class)->orderByDesc('occurred_at'); }
}
