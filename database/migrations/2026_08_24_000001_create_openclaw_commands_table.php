<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_claw_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_reconciliation_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50);
            $table->text('instruction');
            $table->string('status', 30)->default('PENDING')->index();
            $table->string('claimed_by', 100)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('result_summary')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['ai_reconciliation_job_id', 'created_at'], 'openclaw_command_job_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_claw_commands');
    }
};
