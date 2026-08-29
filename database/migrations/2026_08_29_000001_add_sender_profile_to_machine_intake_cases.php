<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_intake_cases', function (Blueprint $table) {
            $table->string('bch_sender_profile', 20)->nullable()->after('bch_email_body');
        });
    }

    public function down(): void
    {
        Schema::table('machine_intake_cases', function (Blueprint $table) {
            $table->dropColumn('bch_sender_profile');
        });
    }
};
