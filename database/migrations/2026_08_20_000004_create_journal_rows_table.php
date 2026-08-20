<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('row_number');
            $table->date('work_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('total_minutes')->nullable();
            $table->text('work_content')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit', 50)->nullable();
            $table->text('work_location')->nullable();
            $table->string('operator_name')->nullable();
            $table->decimal('confidence', 5, 4);
            $table->json('raw_data')->nullable();
            $table->json('exceptions')->nullable();
            $table->timestamps();

            $table->unique(['journal_document_id', 'row_number'], 'journal_rows_document_row_unique');
            $table->index('work_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_rows');
    }
};
