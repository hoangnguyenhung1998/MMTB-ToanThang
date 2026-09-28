<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_photo_ai_rescue_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ocr_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zalo_attachment_id')->constrained()->cascadeOnDelete();
            $table->string('source_sha256', 64);
            $table->string('status', 30)->default('PENDING')->index();
            // MySQL and SQLite both permit multiple NULL values in a unique index.
            // The single non-null value is the database backstop for double-submit.
            $table->string('active_key', 20)->nullable();
            $table->string('claimed_by', 100)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider', 100)->nullable();
            $table->string('model', 150)->nullable();
            $table->string('prompt_version', 100);
            $table->string('schema_version', 100);
            $table->string('classification', 40)->nullable();
            $table->string('extracted_machine', 100)->nullable();
            $table->date('extracted_date')->nullable();
            $table->time('extracted_time')->nullable();
            $table->json('ambiguities')->nullable();
            $table->json('structured_response')->nullable();
            $table->longText('raw_response')->nullable();
            $table->json('validation_outcome')->nullable();
            $table->string('final_resolution', 50)->nullable();
            $table->json('usage')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['ocr_job_id', 'active_key'], 'daily_ai_rescue_one_active_per_job');
            $table->index(['status', 'id'], 'daily_ai_rescue_claim_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_photo_ai_rescue_attempts');
    }
};
