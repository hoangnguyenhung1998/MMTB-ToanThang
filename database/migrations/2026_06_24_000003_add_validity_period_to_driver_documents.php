<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('driver_documents', 'validity_period')) {
                $table->string('validity_period')->nullable()->after('expiry_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('driver_documents', function (Blueprint $table) {
            if (Schema::hasColumn('driver_documents', 'validity_period')) {
                $table->dropColumn('validity_period');
            }
        });
    }
};
