<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_sources', function (Blueprint $t) {
            $t->string('processed_path')->nullable();
            $t->string('processed_sha256', 64)->nullable();
            $t->json('processing')->nullable();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('dossier_sources')->whereNotNull('processed_path')->exists()) {
            throw new RuntimeException('Giữ metadata bản xử lý đang được hồ sơ/manifest tham chiếu.');
        }
        Schema::table('dossier_sources', fn (Blueprint $t) => $t->dropColumn(['processed_path', 'processed_sha256', 'processing']));
    }
};
