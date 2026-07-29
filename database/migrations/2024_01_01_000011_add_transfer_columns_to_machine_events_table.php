<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_events', function (Blueprint $table) {
            $table->foreignId('from_project_id')
                ->nullable()
                ->after('project_id')
                ->constrained('projects')
                ->nullOnDelete();
            $table->foreignId('to_project_id')
                ->nullable()
                ->after('from_project_id')
                ->constrained('projects')
                ->nullOnDelete();
            $table->foreignId('from_command_center_id')
                ->nullable()
                ->after('to_project_id')
                ->constrained('command_centers')
                ->nullOnDelete();
            $table->foreignId('to_command_center_id')
                ->nullable()
                ->after('from_command_center_id')
                ->constrained('command_centers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machine_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_project_id');
            $table->dropConstrainedForeignId('to_project_id');
            $table->dropConstrainedForeignId('from_command_center_id');
            $table->dropConstrainedForeignId('to_command_center_id');
        });
    }
};
