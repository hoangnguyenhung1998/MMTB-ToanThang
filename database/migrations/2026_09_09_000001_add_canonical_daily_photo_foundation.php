<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_photo_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 191)->unique();
            $table->foreignId('machine_id')->constrained()->restrictOnDelete();
            $table->foreignId('machine_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->string('status', 30)->default('COLLECTING')->index();
            $table->string('source_version', 50);
            $table->json('source_metadata')->nullable();
            $table->timestamps();

            $table->index(['machine_id', 'work_date']);
            $table->index(['machine_assignment_id', 'work_date']);
        });

        Schema::table('ocr_jobs', function (Blueprint $table): void {
            $table->string('observed_asset_code', 100)->nullable()->after('asset_code')->index();
            $table->string('machine_resolution_method', 40)->nullable()->after('observed_asset_code')->index();
            $table->json('machine_resolution_metadata')->nullable()->after('machine_resolution_method');
            $table->foreignId('sender_driver_link_id')->nullable()->after('machine_resolution_metadata')
                ->constrained('zalo_sender_driver_links')->nullOnDelete();
            $table->foreignId('machine_driver_history_id')->nullable()->after('sender_driver_link_id')
                ->constrained('machine_driver_histories')->nullOnDelete();
            $table->timestamp('machine_resolved_at')->nullable()->after('machine_driver_history_id');
            $table->foreignId('daily_photo_case_id')->nullable()->after('machine_resolved_at')
                ->constrained('daily_photo_cases')->nullOnDelete();
        });

        Schema::table('zalo_sender_driver_links', function (Blueprint $table): void {
            $table->index(['sender_id', 'valid_from', 'valid_to'], 'sender_driver_effective_at_index');
        });
        Schema::table('machine_driver_histories', function (Blueprint $table): void {
            $table->index(['driver_id', 'started_at', 'ended_at'], 'driver_machine_effective_at_index');
        });
        Schema::table('machine_assignments', function (Blueprint $table): void {
            $table->index(['machine_id', 'time_in', 'time_out'], 'machine_assignment_effective_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('daily_photo_case_id');
            $table->dropConstrainedForeignId('machine_driver_history_id');
            $table->dropConstrainedForeignId('sender_driver_link_id');
            $table->dropColumn([
                'machine_resolved_at',
                'machine_resolution_metadata',
                'machine_resolution_method',
                'observed_asset_code',
            ]);
        });

        Schema::table('machine_assignments', fn (Blueprint $table) => $table->dropIndex('machine_assignment_effective_at_index'));
        Schema::table('machine_driver_histories', fn (Blueprint $table) => $table->dropIndex('driver_machine_effective_at_index'));
        Schema::table('zalo_sender_driver_links', fn (Blueprint $table) => $table->dropIndex('sender_driver_effective_at_index'));
        Schema::dropIfExists('daily_photo_cases');
    }
};
