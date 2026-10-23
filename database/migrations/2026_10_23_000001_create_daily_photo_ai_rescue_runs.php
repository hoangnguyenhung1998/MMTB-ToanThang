<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_photo_ai_rescue_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->json('job_ids');
            $table->json('reason_groups');
            $table->unsignedInteger('selected_count');
            $table->unsignedInteger('cursor')->default(0);
            $table->unsignedInteger('enqueued')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->boolean('enqueue_done')->default(false);
            $table->string('status', 32)->default('QUEUED')->index();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable()->index();
            $table->unsignedInteger('failures')->default(0);
            $table->string('error_code')->nullable();
            $table->unsignedBigInteger('error_job_id')->nullable();
            $table->timestamps();
        });
        Schema::create('daily_photo_ai_rescue_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('daily_photo_ai_rescue_runs');
            $table->unsignedBigInteger('ocr_job_id'); // Deleted selected jobs remain auditable skips.
            $table->foreignId('attempt_id')->nullable()->constrained('daily_photo_ai_rescue_attempts');
            $table->string('category', 32);
            $table->unique(['run_id', 'ocr_job_id'], 'ai_run_job_unique');
            $table->index(['run_id', 'attempt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_photo_ai_rescue_run_items');
        Schema::dropIfExists('daily_photo_ai_rescue_runs');
    }
};
