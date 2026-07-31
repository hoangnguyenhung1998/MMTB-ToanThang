<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_daily_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('machine_assignment_id')->nullable()->constrained('machine_assignments')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('command_center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('machine_state', ['ACTIVE', 'RETURNED', 'NOT_ASSIGNED'])->default('ACTIVE');
            $table->string('change_type')->nullable();
            $table->text('change_note')->nullable();
            $table->timestamps();

            $table->unique(
                ['reconciliation_period_id', 'machine_id', 'work_date'],
                'machine_daily_assignment_unique'
            );
            $table->index(['work_date', 'project_id', 'command_center_id'], 'machine_daily_assignment_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_daily_assignments');
    }
};
