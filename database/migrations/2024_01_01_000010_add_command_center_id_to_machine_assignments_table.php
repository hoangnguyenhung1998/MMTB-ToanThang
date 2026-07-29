<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_assignments', function (Blueprint $table) {
            $table->foreignId('command_center_id')
                ->nullable()
                ->after('project_id')
                ->constrained('command_centers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machine_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('command_center_id');
        });
    }
};
