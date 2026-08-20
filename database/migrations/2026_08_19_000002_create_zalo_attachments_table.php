<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zalo_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zalo_message_id')
                ->constrained('zalo_messages')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('attachment_index');
            $table->string('original_name')->nullable();
            $table->string('storage_disk', 50)->default('local');
            $table->string('storage_path');
            $table->char('sha256', 64);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->string('status', 30)->default('STORED');
            $table->unsignedBigInteger('duplicate_of_attachment_id')->nullable();
            $table->timestamps();

            $table->unique(['zalo_message_id', 'attachment_index'], 'zalo_attachments_message_index_unique');
            $table->index('sha256');
            $table->index('duplicate_of_attachment_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zalo_attachments');
    }
};
