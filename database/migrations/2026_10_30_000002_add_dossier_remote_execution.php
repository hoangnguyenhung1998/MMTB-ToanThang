<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_worker_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('kind', 24);
            $t->string('subject_id', 64);
            $t->string('dedupe_key', 64)->unique();
            $t->string('pipeline', 64);
            $t->json('input');
            $t->string('input_hash', 64);
            $t->string('status', 24)->default('pending')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('worker_id', 100)->nullable();
            $t->uuid('claim_token')->nullable();
            $t->string('ai_profile', 64)->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('available_at')->nullable();
            $t->unsignedInteger('progress')->default(0);
            $t->string('stage', 32)->nullable();
            $t->string('result_hash', 64)->nullable();
            $t->json('result')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->timestamps();
            $t->index(['kind', 'status', 'available_at']);
        });
        Schema::create('dossier_objects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('disk', 64);
            $t->string('key', 700);
            $t->string('sha256', 64);
            $t->unsignedBigInteger('bytes');
            $t->string('mime', 100);
            $t->string('purpose', 24);
            $t->string('status', 24)->default('staged');
            $t->foreignUuid('job_id')->nullable()->constrained('dossier_worker_jobs')->restrictOnDelete();
            $t->unsignedInteger('attempt')->nullable();
            $t->string('slot', 64)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['job_id', 'attempt', 'slot']);
            $t->index(['sha256', 'status']);
        });
        Schema::create('dossier_workers', function (Blueprint $t) {
            $t->string('id', 100)->primary();
            $t->json('capabilities');
            $t->json('versions');
            $t->boolean('ai_ready')->default(false);
            $t->string('ai_profile', 64)->nullable();
            $t->string('provider', 100)->nullable();
            $t->string('model', 200)->nullable();
            $t->timestamp('last_seen_at');
            $t->timestamps();
        });
        Schema::table('dossier_sources', function (Blueprint $t) {
            $t->foreignUuid('object_id')->nullable()->constrained('dossier_objects')->restrictOnDelete();
            $t->foreignUuid('processed_object_id')->nullable()->constrained('dossier_objects')->restrictOnDelete();
            $t->json('previews')->nullable();
        });
        Schema::table('dossier_exports', fn (Blueprint $t) => $t->foreignUuid('object_id')->nullable()->constrained('dossier_objects')->restrictOnDelete());
        Schema::table('dossier_ai_pages', function (Blueprint $t) {
            $t->foreignUuid('image_object_id')->nullable()->constrained('dossier_objects')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(0);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain remote objects, leases and references. Roll back code without deleting dossier history.');
    }
};
