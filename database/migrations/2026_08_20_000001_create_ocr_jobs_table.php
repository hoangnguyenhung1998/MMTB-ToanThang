<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zalo_attachment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('PENDING')->index();
            $table->string('claimed_by', 100)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->date('extracted_date')->nullable();
            $table->time('extracted_time')->nullable();
            $table->string('asset_code', 100)->nullable()->index();
            $table->string('operator_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('work_location')->nullable();
            $table->string('shift', 30)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->longText('raw_text')->nullable();
            $table->json('exceptions')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        DB::table('zalo_attachments')
            ->where('status', 'STORED')
            ->orderBy('id')
            ->eachById(function (object $attachment): void {
                DB::table('ocr_jobs')->insertOrIgnore([
                    'zalo_attachment_id' => $attachment->id,
                    'status' => 'PENDING',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_jobs');
    }
};
