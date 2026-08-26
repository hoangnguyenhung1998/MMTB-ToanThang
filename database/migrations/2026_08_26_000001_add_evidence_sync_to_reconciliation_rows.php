<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_rows', function (Blueprint $table): void {
            $table->string('evidence_status', 30)->default('NO_EVIDENCE')->index()->after('change_note');
            $table->json('daily_ocr_job_ids')->nullable()->after('evidence_status');
            $table->json('journal_row_ids')->nullable()->after('daily_ocr_job_ids');
            $table->unsignedBigInteger('ai_reconciliation_job_id')->nullable()->index()->after('journal_row_ids');
            $table->unsignedBigInteger('ai_reconciliation_submission_id')->nullable()->after('ai_reconciliation_job_id');
            $table->char('evidence_signature', 64)->nullable()->after('ai_reconciliation_submission_id');
            $table->text('evidence_summary')->nullable()->after('evidence_signature');
            $table->boolean('has_evidence_changes')->default(false)->index()->after('evidence_summary');
            $table->timestamp('evidence_synced_at')->nullable()->after('has_evidence_changes');
            $table->timestamp('manually_edited_at')->nullable()->after('evidence_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_rows', function (Blueprint $table): void {
            $table->dropIndex(['evidence_status']);
            $table->dropIndex(['ai_reconciliation_job_id']);
            $table->dropIndex(['has_evidence_changes']);
            $table->dropColumn([
                'evidence_status', 'daily_ocr_job_ids', 'journal_row_ids',
                'ai_reconciliation_job_id', 'ai_reconciliation_submission_id',
                'evidence_signature', 'evidence_summary', 'has_evidence_changes',
                'evidence_synced_at', 'manually_edited_at',
            ]);
        });
    }
};
