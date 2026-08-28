<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_intake_email_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_intake_case_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gmail_message_id')->unique();
            $table->string('gmail_thread_id')->nullable()->index();
            $table->string('sender')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('candidate_asset_code')->nullable()->index();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('match_method', 40)->nullable();
            $table->string('status', 30)->default('PENDING')->index();
            $table->string('evidence_disk', 30)->nullable();
            $table->string('evidence_path')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_intake_email_replies');
    }
};
