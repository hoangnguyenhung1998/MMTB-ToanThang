<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_batches', fn (Blueprint $table) => $table->boolean('auto_analyze')->nullable());
        DB::table('dossier_types')->insert([
            'name' => 'Danh sách huấn luyện ATLĐ', 'ai_key' => 'safety_training',
            'groups' => '["driver"]', 'required_groups' => '[]', 'expiry_mode' => 'review',
            'warning_days' => '[30,15,7]', 'sort_order' => 35,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        throw new \RuntimeException('Giữ nguyên cấu hình đợt và danh mục đã giao hồ sơ.');
    }
};
