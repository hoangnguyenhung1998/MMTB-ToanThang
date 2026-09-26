<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_regression_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_ocr_job_id')->nullable()->constrained('ocr_jobs')->nullOnDelete();
            $table->foreignId('source_attachment_id')->nullable()->constrained('zalo_attachments')->nullOnDelete();
            $table->string('document_type', 40)->default('DAILY_TIMEMARK');
            $table->string('case_category', 40);
            $table->json('input_snapshot');
            $table->json('source_metadata')->nullable();
            $table->string('current_machine', 100)->nullable();
            $table->date('current_date')->nullable();
            $table->time('current_time')->nullable();
            $table->string('expected_machine', 100)->nullable();
            $table->date('expected_date')->nullable();
            $table->time('expected_time')->nullable();
            $table->string('expected_disposition', 40);
            $table->string('expected_status', 40)->default('AUTO');
            $table->text('notes')->nullable();
            $table->string('verification_status', 20)->default('DRAFT');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_ocr_job_id', 'case_category'], 'ocr_cases_source_category_unique');
            $table->index(['verification_status', 'case_category'], 'ocr_cases_verification_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_regression_cases');
    }
};
