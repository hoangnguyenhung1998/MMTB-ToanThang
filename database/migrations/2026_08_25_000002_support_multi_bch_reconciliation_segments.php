<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_rows', function (Blueprint $table): void {
            $table->index('reconciliation_period_id', 'reconciliation_row_period_fk_support');
        });

        Schema::table('reconciliation_rows', function (Blueprint $table): void {
            $table->dropUnique('reconciliation_row_unique');
            $table->time('segment_start')->nullable()->after('work_date');
            $table->time('segment_end')->nullable()->after('segment_start');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE reconciliation_rows AS rows_table
                INNER JOIN machine_assignments AS assignments
                    ON assignments.id = rows_table.machine_assignment_id
                SET
                    rows_table.segment_start = CASE
                        WHEN DATE(assignments.time_in) = rows_table.work_date THEN TIME(assignments.time_in)
                        ELSE '00:00:00'
                    END,
                    rows_table.segment_end = CASE
                        WHEN assignments.time_out IS NOT NULL
                            AND DATE(assignments.time_out) = rows_table.work_date
                        THEN TIME(assignments.time_out)
                        ELSE '23:59:59'
                    END
                SQL);
        } else {
            DB::table('reconciliation_rows')
                ->join('machine_assignments', 'machine_assignments.id', '=', 'reconciliation_rows.machine_assignment_id')
                ->select([
                    'reconciliation_rows.id',
                    'reconciliation_rows.work_date',
                    'machine_assignments.time_in',
                    'machine_assignments.time_out',
                ])
                ->orderBy('reconciliation_rows.id')
                ->chunk(500, function ($rows): void {
                    foreach ($rows as $row) {
                        $workDate = Carbon::parse($row->work_date);
                        $timeIn = Carbon::parse($row->time_in);
                        $timeOut = $row->time_out ? Carbon::parse($row->time_out) : null;

                        DB::table('reconciliation_rows')->where('id', $row->id)->update([
                            'segment_start' => $workDate->isSameDay($timeIn) ? $timeIn->format('H:i:s') : '00:00:00',
                            'segment_end' => $timeOut && $workDate->isSameDay($timeOut) ? $timeOut->format('H:i:s') : '23:59:59',
                        ]);
                    }
                });
        }

        Schema::table('reconciliation_rows', function (Blueprint $table): void {
            $table->unique(
                ['reconciliation_period_id', 'machine_id', 'work_date', 'machine_assignment_id'],
                'reconciliation_row_segment_unique'
            );
            $table->index(
                ['machine_id', 'work_date', 'segment_start', 'segment_end'],
                'reconciliation_row_time_lookup'
            );
        });

        Schema::table('reconciliation_rows', function (Blueprint $table): void {
            $table->dropIndex('reconciliation_row_period_fk_support');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_rows', function (Blueprint $table): void {
            $table->index('reconciliation_period_id', 'reconciliation_row_period_fk_support');
        });

        Schema::table('reconciliation_rows', function (Blueprint $table): void {
            $table->dropIndex('reconciliation_row_time_lookup');
            $table->dropUnique('reconciliation_row_segment_unique');
            $table->dropColumn(['segment_start', 'segment_end']);
            $table->unique(
                ['reconciliation_period_id', 'machine_id', 'work_date'],
                'reconciliation_row_unique'
            );
        });

        Schema::table('reconciliation_rows', function (Blueprint $table): void {
            $table->dropIndex('reconciliation_row_period_fk_support');
        });
    }
};
