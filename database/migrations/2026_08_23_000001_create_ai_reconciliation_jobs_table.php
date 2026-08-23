<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reconciliation_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->char('source_signature', 64);
            $table->string('status', 30)->default('PENDING')->index();
            $table->string('claimed_by', 100)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['machine_id', 'work_date'], 'ai_reconciliation_machine_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reconciliation_jobs');
    }
};
