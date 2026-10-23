<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_photo_recovery_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('active_slot', 64)->nullable()->unique();
            $table->json('filters');
            $table->string('mode', 24)->default('RECOVERY');
            $table->string('status', 24)->default('QUEUED')->index();
            $table->unsignedBigInteger('job_ceiling');
            $table->unsignedBigInteger('job_cursor')->default(0);
            $table->unsignedBigInteger('period_ceiling');
            $table->boolean('discovery_done')->default(false);
            $table->json('counters');
            $table->unsignedInteger('failures')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable()->index();
            $table->string('error_code')->nullable();
            $table->json('error_context')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('daily_photo_recovery_work', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('daily_photo_recovery_runs')->cascadeOnDelete();
            $table->string('work_key', 100);
            $table->string('stage', 12);
            $table->json('payload');
            $table->unsignedBigInteger('period_cursor')->default(0);
            $table->boolean('done')->default(false);
            $table->unique(['run_id', 'work_key']);
            $table->index(['run_id', 'done', 'stage', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_photo_recovery_work');
        Schema::dropIfExists('daily_photo_recovery_runs');
    }
};
