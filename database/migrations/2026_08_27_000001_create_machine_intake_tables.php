<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_intake_cases', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable()->unique();
            $table->string('status', 40)->default('NEW')->index();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_channel', 30)->default('WEB');
            $table->string('source_message_id')->nullable();
            $table->string('email_thread_id')->nullable()->index();
            $table->string('email_message_id')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->string('company', 20)->nullable();
            $table->string('chassis_no')->nullable()->index();
            $table->string('chassis_no_raw')->nullable();
            $table->string('engine_no')->nullable();
            $table->string('engine_no_raw')->nullable();
            $table->string('plate_no')->nullable();
            $table->string('machine_type')->nullable();
            $table->string('model_name')->nullable();
            $table->unsignedSmallInteger('manufacture_year')->nullable();
            $table->string('asset_code')->nullable()->index();
            $table->string('asset_code_raw')->nullable();
            $table->string('asset_code_source', 40)->nullable();
            $table->text('asset_code_source_note')->nullable();
            $table->string('code_evidence_path')->nullable();
            $table->timestamp('code_received_at')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('command_center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('handover_at')->nullable();
            $table->string('handover_evidence_path')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('machine_intake_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_intake_case_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->string('storage_disk', 30)->default('public');
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->string('sha256', 64);
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('extraction_status', 30)->default('PENDING');
            $table->json('extraction_json')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamps();
            $table->unique(['machine_intake_case_id', 'sha256']);
        });

        Schema::create('machine_intake_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_intake_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 80)->index();
            $table->json('properties')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['machine_intake_case_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_intake_events');
        Schema::dropIfExists('machine_intake_documents');
        Schema::dropIfExists('machine_intake_cases');
    }
};
