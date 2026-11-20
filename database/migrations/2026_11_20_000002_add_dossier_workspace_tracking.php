<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_sources', fn (Blueprint $table) => $table->json('document_target')->nullable());
        Schema::table('dossier_worker_jobs', function (Blueprint $table) {
            $table->json('attempt_history')->nullable();
            $table->timestamp('queued_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Keep dossier targets and processing history.');
    }
};
