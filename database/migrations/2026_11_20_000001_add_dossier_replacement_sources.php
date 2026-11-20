<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_sources', function (Blueprint $table) {
            $table->foreignId('replacement_version_id')->nullable()->constrained('dossier_versions')->restrictOnDelete();
            $table->timestamp('replacement_cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Giữ tham chiếu nguồn và lịch sử thay giấy.');
    }
};
