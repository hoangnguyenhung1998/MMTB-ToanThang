<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zalo_sender_machine_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('sender_id', 100)->index();
            $table->string('active_sender_id', 100)->nullable()->unique();
            $table->foreignId('machine_id')->constrained()->restrictOnDelete();
            $table->dateTime('valid_from');
            $table->dateTime('valid_to')->nullable();
            $table->string('source', 30);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sender_id', 'valid_from', 'valid_to']);
        });
        Schema::table('daily_photo_case_evidence', function (Blueprint $table): void {
            $table->dateTime('capture_datetime')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep capture_datetime nullable: reverting it would destroy date-only evidence.
        Schema::dropIfExists('zalo_sender_machine_mappings');
    }
};
