<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_photo_case_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_photo_case_id')->constrained('daily_photo_cases')->cascadeOnDelete();
            $table->foreignId('ocr_job_id')->unique()->constrained('ocr_jobs')->cascadeOnDelete();
            $table->dateTime('capture_datetime');
            $table->string('assignment_resolution_status', 30)->nullable();
            $table->string('pairing_state', 30)->default('UNMATCHED')->index();
            $table->string('pairing_diagnostic', 50)->nullable();
            $table->timestamps();

            $table->index(['daily_photo_case_id', 'capture_datetime', 'ocr_job_id'], 'daily_photo_evidence_order_index');
        });

        Schema::create('daily_photo_intervals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_photo_case_id')->constrained('daily_photo_cases')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('start_evidence_id')->unique()->constrained('daily_photo_case_evidence')->cascadeOnDelete();
            $table->foreignId('end_evidence_id')->unique()->constrained('daily_photo_case_evidence')->cascadeOnDelete();
            $table->dateTime('raw_start_at');
            $table->dateTime('raw_end_at');
            $table->time('raw_start_time');
            $table->time('raw_end_time');
            $table->string('status', 30)->default('PAIRED');
            $table->string('pairing_policy_version', 50);
            $table->timestamps();

            $table->unique(['daily_photo_case_id', 'sequence']);
        });

        Schema::table('daily_photo_cases', function (Blueprint $table): void {
            $table->string('pairing_policy_version', 50)->nullable()->after('source_version');
            $table->json('pairing_diagnostics')->nullable()->after('source_metadata');
            $table->timestamp('pairing_computed_at')->nullable()->after('pairing_diagnostics');
        });
    }

    public function down(): void
    {
        Schema::table('daily_photo_cases', function (Blueprint $table): void {
            $table->dropColumn(['pairing_policy_version', 'pairing_diagnostics', 'pairing_computed_at']);
        });
        Schema::dropIfExists('daily_photo_intervals');
        Schema::dropIfExists('daily_photo_case_evidence');
    }
};
