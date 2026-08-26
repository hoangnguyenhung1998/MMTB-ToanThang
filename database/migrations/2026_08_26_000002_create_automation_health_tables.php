<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('node_key', 100)->unique();
            $table->string('name');
            $table->string('location')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->string('agent_version', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_node_id')->constrained()->cascadeOnDelete();
            $table->string('service_key', 100);
            $table->string('name');
            $table->string('service_type', 50)->index();
            $table->string('reported_status', 20)->default('HEALTHY')->index();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamp('last_success_at')->nullable();
            $table->string('version', 50)->nullable();
            $table->string('current_job')->nullable();
            $table->unsignedInteger('queue_depth')->nullable();
            $table->unsignedInteger('consecutive_errors')->default(0);
            $table->string('last_error_code', 100)->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->unique(['automation_node_id', 'service_key'], 'automation_service_node_key_unique');
        });

        Schema::create('automation_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_service_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->string('severity', 20)->index();
            $table->string('status', 20)->default('OPEN')->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_incidents');
        Schema::dropIfExists('automation_services');
        Schema::dropIfExists('automation_nodes');
    }
};
