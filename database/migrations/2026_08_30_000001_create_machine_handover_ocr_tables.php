<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('machine_assignments', 'handover_date')) {
            Schema::table('machine_assignments', fn (Blueprint $table) => $table->date('handover_date')->nullable()->after('time_in'));
        }
        if (! Schema::hasColumn('machine_events', 'event_date')) {
            Schema::table('machine_events', fn (Blueprint $table) => $table->date('event_date')->nullable()->after('occurred_at'));
        }

        if (! Schema::hasTable('machine_handover_cases')) Schema::create('machine_handover_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_intake_case_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('NEW')->index();
            $table->date('handover_date')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('command_center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('extracted_asset_code')->nullable();
            $table->string('extracted_project_text')->nullable();
            $table->string('extracted_command_center_text')->nullable();
            $table->json('extraction')->nullable();
            $table->json('review_flags')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('missing_data_alerted_at')->nullable();
            $table->timestamp('ready_alerted_at')->nullable();
            $table->timestamp('reminder_alerted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['machine_id', 'status']);
        });

        if (! Schema::hasTable('machine_handover_documents')) Schema::create('machine_handover_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_handover_case_id')->constrained()->cascadeOnDelete();
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
            $table->unique(['machine_handover_case_id', 'sha256'], 'handover_case_sha256_unique');
        });

        if (! $this->hasIndex('machine_handover_documents', 'handover_case_sha256_unique')) {
            Schema::table('machine_handover_documents', fn (Blueprint $table) =>
                $table->unique(['machine_handover_case_id', 'sha256'], 'handover_case_sha256_unique')
            );
        }

        if (! Schema::hasTable('machine_handover_ocr_jobs')) Schema::create('machine_handover_ocr_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_handover_document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('PENDING')->index();
            $table->string('claimed_by')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('extraction')->nullable();
            $table->json('review_flags')->nullable();
            $table->longText('raw_text')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_handover_ocr_jobs');
        Schema::dropIfExists('machine_handover_documents');
        Schema::dropIfExists('machine_handover_cases');
        if (Schema::hasColumn('machine_events', 'event_date')) {
            Schema::table('machine_events', fn (Blueprint $table) => $table->dropColumn('event_date'));
        }
        if (Schema::hasColumn('machine_assignments', 'handover_date')) {
            Schema::table('machine_assignments', fn (Blueprint $table) => $table->dropColumn('handover_date'));
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $name
        );
    }
};
