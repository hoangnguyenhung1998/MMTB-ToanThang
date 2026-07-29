<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (!Schema::hasColumn('machines', 'returned_to_app')) {
                $table->boolean('returned_to_app')->default(true)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (Schema::hasColumn('machines', 'returned_to_app')) {
                $table->dropColumn('returned_to_app');
            }
        });
    }
};
