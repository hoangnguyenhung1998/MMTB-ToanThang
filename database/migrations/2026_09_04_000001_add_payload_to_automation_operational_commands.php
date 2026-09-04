<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_operational_commands', function (Blueprint $table): void {
            $table->json('payload')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('automation_operational_commands', function (Blueprint $table): void {
            $table->dropColumn('payload');
        });
    }
};
