<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table): void {
            $table->string('document_type', 30)->default('UNKNOWN')->index()->after('status');
            $table->decimal('classification_confidence', 5, 4)->nullable()->after('document_type');
            $table->string('classified_by', 100)->nullable()->after('classification_confidence');
            $table->timestamp('classified_at')->nullable()->after('classified_by');
            $table->index(['machine_id', 'extracted_date'], 'ocr_jobs_machine_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table): void {
            $table->dropIndex('ocr_jobs_machine_date_index');
            $table->dropColumn([
                'document_type',
                'classification_confidence',
                'classified_by',
                'classified_at',
            ]);
        });
    }
};
