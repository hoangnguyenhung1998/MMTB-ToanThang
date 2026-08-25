<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_rows', function (Blueprint $table): void {
            $table->time('regular_morning_start')->nullable()->after('confirmed_check_out');
            $table->time('regular_morning_end')->nullable()->after('regular_morning_start');
            $table->time('regular_afternoon_start')->nullable()->after('regular_morning_end');
            $table->time('regular_afternoon_end')->nullable()->after('regular_afternoon_start');
            $table->time('overtime_lunch_start')->nullable()->after('regular_afternoon_end');
            $table->time('overtime_lunch_end')->nullable()->after('overtime_lunch_start');
            $table->time('overtime_afternoon_start')->nullable()->after('overtime_lunch_end');
            $table->time('overtime_afternoon_end')->nullable()->after('overtime_afternoon_start');
            $table->time('overtime_evening_start')->nullable()->after('overtime_afternoon_end');
            $table->time('overtime_evening_end')->nullable()->after('overtime_evening_start');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_rows', function (Blueprint $table): void {
            $table->dropColumn([
                'regular_morning_start',
                'regular_morning_end',
                'regular_afternoon_start',
                'regular_afternoon_end',
                'overtime_lunch_start',
                'overtime_lunch_end',
                'overtime_afternoon_start',
                'overtime_afternoon_end',
                'overtime_evening_start',
                'overtime_evening_end',
            ]);
        });
    }
};
