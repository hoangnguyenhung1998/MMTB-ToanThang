<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zalo_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('group_id', 100);
            $table->string('message_id', 100);
            $table->string('sender_id', 100)->nullable();
            $table->string('sender_name')->nullable();
            $table->dateTime('sent_at');
            $table->dateTime('received_at');
            $table->json('raw_payload')->nullable();
            $table->string('status', 30)->default('RECEIVED');
            $table->timestamps();

            $table->unique(['group_id', 'message_id'], 'zalo_messages_group_message_unique');
            $table->index(['group_id', 'sent_at']);
            $table->index(['sender_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zalo_messages');
    }
};
