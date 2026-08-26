<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_services', function (Blueprint $table): void {
            $table->timestamp('last_api_success_at')->nullable()->after('last_success_at');
            $table->timestamp('last_job_success_at')->nullable()->after('last_api_success_at');
            $table->timestamp('current_job_started_at')->nullable()->after('current_job');
        });

        Schema::create('automation_operational_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->string('status', 20)->default('PENDING')->index();
            $table->string('claimed_by')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_health_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_incident_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_key')->unique();
            $table->string('kind', 30);
            $table->string('status', 20)->default('PENDING')->index();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_health_alerts');
        Schema::dropIfExists('automation_operational_commands');
        Schema::table('automation_services', function (Blueprint $table): void {
            $table->dropColumn(['last_api_success_at', 'last_job_success_at', 'current_job_started_at']);
        });
    }
};
