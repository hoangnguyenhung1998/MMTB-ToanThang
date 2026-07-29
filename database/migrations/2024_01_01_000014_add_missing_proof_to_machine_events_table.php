<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_events', function (Blueprint $table) {
            if (!Schema::hasColumn('machine_events', 'missing_proof')) {
                $table->boolean('missing_proof')->default(false)->index()->after('proof_file_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('machine_events', function (Blueprint $table) {
            if (Schema::hasColumn('machine_events', 'missing_proof')) {
                $table->dropColumn('missing_proof');
            }
        });
    }
};
