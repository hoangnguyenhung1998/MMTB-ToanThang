<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_processing_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ocr_job_id')->constrained()->cascadeOnDelete();
            $table->string('worker_id', 100);
            $table->string('stage', 30)->index();
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 20)->default('PROCESSING')->index();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['ocr_job_id', 'attempt'], 'ocr_processing_runs_job_attempt_unique');
            $table->index(['status', 'started_at'], 'ocr_processing_runs_status_started_index');
        });

        Schema::table('ocr_jobs', function (Blueprint $table): void {
            $table->index(['status', 'processed_at'], 'ocr_jobs_monitor_status_processed_index');
            $table->index('created_at', 'ocr_jobs_monitor_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table): void {
            $table->dropIndex('ocr_jobs_monitor_status_processed_index');
            $table->dropIndex('ocr_jobs_monitor_created_index');
        });
        Schema::dropIfExists('ocr_processing_runs');
    }
};
