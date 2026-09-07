<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('zalo_sender_driver_links', function (Blueprint $table) {
            $table->id();
            $table->string('sender_id', 100)->index();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->dateTime('valid_from');
            $table->dateTime('valid_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('ocr_jobs', function (Blueprint $table) {
            $table->json('daily_metadata')->nullable();
        });
        Schema::table('reconciliation_rows', function (Blueprint $table) {
            $table->json('daily_intervals')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_rows', fn (Blueprint $table) => $table->dropColumn('daily_intervals'));
        Schema::table('ocr_jobs', fn (Blueprint $table) => $table->dropColumn('daily_metadata'));
        Schema::dropIfExists('zalo_sender_driver_links');
    }
};
