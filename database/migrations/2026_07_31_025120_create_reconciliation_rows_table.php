<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_assignment_id')->nullable()->constrained('machine_assignments')->nullOnDelete();
            $table->date('work_date');

            // Snapshot at the time the period is generated.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('command_center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->string('change_type')->nullable();
            $table->text('change_note')->nullable();

            // OCR values: raw -> rounded -> confirmed.
            $table->time('ocr_check_in_raw')->nullable();
            $table->time('ocr_check_out_raw')->nullable();
            $table->time('rounded_check_in')->nullable();
            $table->time('rounded_check_out')->nullable();
            $table->time('confirmed_check_in')->nullable();
            $table->time('confirmed_check_out')->nullable();

            // Store durations as minutes to avoid decimal-hour rounding errors.
            $table->unsignedSmallInteger('regular_minutes')->nullable();
            $table->unsignedSmallInteger('lunch_minutes')->nullable();
            $table->unsignedSmallInteger('ot_afternoon_minutes')->nullable();
            $table->unsignedSmallInteger('ot_evening_minutes')->nullable();

            $table->time('gps_check_in')->nullable();
            $table->time('gps_check_out')->nullable();
            $table->unsignedSmallInteger('gps_check_in_diff_minutes')->nullable();
            $table->unsignedSmallInteger('gps_check_out_diff_minutes')->nullable();

            $table->string('work_location')->nullable();
            $table->text('work_content')->nullable();
            $table->text('explanation')->nullable();
            $table->text('notes')->nullable();

            $table->enum('status', ['DRAFT', 'REVIEWED', 'CONFIRMED', 'REJECTED'])->default('DRAFT');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['reconciliation_period_id', 'machine_id', 'work_date'],
                'reconciliation_row_unique'
            );
            $table->index(['work_date', 'status'], 'reconciliation_row_status_lookup');
            $table->index(
                ['project_id', 'command_center_id', 'work_date'],
                'reconciliation_row_scope'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_rows');
    }
};
