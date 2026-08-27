<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_intake_cases', function (Blueprint $table) {
            $table->json('extraction_summary')->nullable()->after('last_error');
            $table->json('review_flags')->nullable()->after('extraction_summary');
        });

        Schema::create('machine_intake_ocr_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_intake_document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('PENDING')->index();
            $table->string('claimed_by', 100)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('extraction')->nullable();
            $table->json('review_flags')->nullable();
            $table->longText('raw_text')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_intake_ocr_jobs');
        Schema::table('machine_intake_cases', function (Blueprint $table) {
            $table->dropColumn(['extraction_summary', 'review_flags']);
        });
    }
};
