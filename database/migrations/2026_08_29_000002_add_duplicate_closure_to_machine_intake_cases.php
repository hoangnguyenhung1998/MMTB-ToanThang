<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_intake_cases', function (Blueprint $table) {
            $table->foreignId('duplicate_machine_id')->nullable()->after('machine_id')->constrained('machines')->nullOnDelete();
            $table->text('duplicate_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machine_intake_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_machine_id');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['duplicate_reason', 'closed_at']);
        });
    }
};
