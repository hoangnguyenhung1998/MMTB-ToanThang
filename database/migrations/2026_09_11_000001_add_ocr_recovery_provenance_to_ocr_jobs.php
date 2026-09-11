<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table): void {
            $table->json('ocr_initial_extraction')->nullable()->after('daily_metadata');
            $table->string('ocr_retry_reason', 60)->nullable()->after('ocr_initial_extraction')->index();
            $table->unsignedTinyInteger('ocr_retry_attempts')->default(0)->after('ocr_retry_reason');
            $table->string('ocr_final_source', 30)->nullable()->after('ocr_retry_attempts')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table): void {
            $table->dropIndex(['ocr_retry_reason']);
            $table->dropIndex(['ocr_final_source']);
            $table->dropColumn([
                'ocr_initial_extraction',
                'ocr_retry_reason',
                'ocr_retry_attempts',
                'ocr_final_source',
            ]);
        });
    }
};
