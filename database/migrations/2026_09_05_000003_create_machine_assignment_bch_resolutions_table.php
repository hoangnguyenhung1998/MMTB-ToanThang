<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_assignment_bch_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_assignment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('command_center_id')->constrained()->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->default('Phục hồi BCH lịch sử bị mất liên kết.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_assignment_bch_resolutions');
    }
};
