<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ocr_job_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asset_code', 100)->nullable()->index();
            $table->decimal('confidence', 5, 4);
            $table->longText('raw_text')->nullable();
            $table->json('exceptions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_documents');
    }
};
