<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reconciliation_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_reconciliation_job_id')->constrained()->cascadeOnDelete();
            $table->uuid('submission_uuid')->unique();
            $table->string('outcome', 30)->index();
            $table->text('summary')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('agent_name', 100);
            $table->string('model', 150)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reconciliation_submissions');
    }
};
