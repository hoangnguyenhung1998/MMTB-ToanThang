<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', fn (Blueprint $table) => $table->string('zalo_sender_id', 100)->nullable()->unique());
        Schema::table('dossier_sources', fn (Blueprint $table) => $table->timestamp('driver_reset_retired_at')->nullable());
        Schema::create('dossier_driver_resets', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->timestamp('completed_at')->nullable();
            $table->json('inventory')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Keep sender identifiers and the reset ledger.');
    }
};
