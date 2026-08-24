<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_rows', function (Blueprint $table): void {
            $table->text('error_explanation')->nullable()->after('work_content');
        });
    }

    public function down(): void
    {
        Schema::table('journal_rows', function (Blueprint $table): void {
            $table->dropColumn('error_explanation');
        });
    }
};
