<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reconciliation_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_reconciliation_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_reconciliation_submission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30)->index();
            $table->string('severity', 20)->index();
            $table->string('fingerprint', 191)->unique();
            $table->string('status', 20)->default('PENDING')->index();
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reconciliation_alerts');
    }
};
