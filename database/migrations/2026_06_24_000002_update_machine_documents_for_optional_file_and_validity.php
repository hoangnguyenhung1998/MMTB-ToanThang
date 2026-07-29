<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_documents', function (Blueprint $table) {
            $table->string('file_path')->nullable()->change();
            if (!Schema::hasColumn('machine_documents', 'validity_period')) {
                $table->string('validity_period')->nullable()->after('expiry_date');
            }
        });

        Schema::table('driver_documents', function (Blueprint $table) {
            $table->string('file_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('machine_documents', function (Blueprint $table) {
            if (Schema::hasColumn('machine_documents', 'validity_period')) {
                $table->dropColumn('validity_period');
            }
        });
    }
};
