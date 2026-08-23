<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reconciliation_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_reconciliation_submission_id')->constrained()->cascadeOnDelete();
            $table->string('code', 100)->index();
            $table->string('severity', 20)->index();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->json('evidence')->nullable();
            $table->text('suggested_action')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reconciliation_findings');
    }
};
