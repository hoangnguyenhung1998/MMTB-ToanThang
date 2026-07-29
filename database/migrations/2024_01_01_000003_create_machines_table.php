<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code')->unique();
            $table->enum('company', ['VINCONS', 'VINALPHA']);
            $table->string('chassis_no')->unique();
            $table->string('engine_no')->nullable();
            $table->string('plate_no')->nullable();
            $table->string('machine_type')->nullable();
            $table->enum('status', ['WAIT_HANDOVER', 'HANDED_OVER', 'ACTIVE', 'RETURNED']);
            $table->boolean('gps_file_added')->default(false);
            $table->foreignId('current_driver_id')
                ->nullable()
                ->constrained('drivers')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
