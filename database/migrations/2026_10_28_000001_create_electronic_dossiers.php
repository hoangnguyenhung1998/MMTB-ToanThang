<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
        Schema::create('dossier_jobs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        Schema::table('machines', function (Blueprint $t) {
            $t->string('dossier_group')->default('machine');
        });
        Schema::create('dossier_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->json('groups');
            $t->json('required_groups');
            $t->string('expiry_mode')->default('review');
            $t->json('warning_days');
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('dossier_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('dossier_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('batch_id')->constrained('dossier_batches')->restrictOnDelete();
            $t->foreignId('machine_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('original_name');
            $t->string('sha256', 64)->unique();
            $t->string('path');
            $t->unsignedBigInteger('bytes');
            $t->string('resolution');
            $t->json('candidates');
            $t->string('status')->default('queued')->index();
            $t->unsignedInteger('page_count')->nullable();
            $t->unsignedInteger('progress')->default(0);
            $t->json('excluded_pages')->nullable();
            $t->boolean('machine_confirmed')->default(false);
            $t->boolean('confirmed')->default(false);
            $t->unsignedInteger('revision')->default(0);
            $t->string('error', 1000)->nullable();
            $t->timestamps();
        });
        Schema::create('dossier_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('type_id')->constrained('dossier_types')->restrictOnDelete();
            $t->foreignId('machine_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('assignment_id')->nullable()->constrained('machine_assignments')->restrictOnDelete();
            $t->string('instance')->default('main');
            $t->string('scope_key')->unique();
            $t->timestamps();
        });
        Schema::create('dossier_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('record_id')->constrained('dossier_records')->restrictOnDelete();
            $t->foreignId('replaces_id')->nullable()->constrained('dossier_versions')->restrictOnDelete();
            $t->foreignId('corrects_id')->nullable()->constrained('dossier_versions')->restrictOnDelete();
            $t->foreignId('source_id')->constrained('dossier_sources')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_id')->unique();
            $t->json('pages');
            $t->boolean('shared_pages')->default(false);
            $t->string('number')->nullable();
            $t->string('issuer')->nullable();
            $t->date('issued_on')->nullable();
            $t->date('certified_on')->nullable();
            $t->dateTime('effective_at');
            $t->string('expiry_precision');
            $t->string('expiry_value')->nullable();
            $t->string('month_rule')->nullable();
            $t->text('note')->nullable();
            $t->text('reason');
            $t->boolean('confirmed')->default(false);
            $t->boolean('archived')->default(false);
            $t->timestamps();
            $t->index(['record_id', 'effective_at']);
        });
        Schema::create('dossier_exports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('machine_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_id')->unique();
            $t->json('manifest');
            $t->json('warnings');
            $t->string('status')->default('queued')->index();
            $t->string('path')->nullable();
            $t->string('sha256', 64)->nullable();
            $t->string('error', 1000)->nullable();
            $t->timestamps();
        });
        Schema::create('dossier_sender_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('sender_id', 100)->unique();
            $t->foreignId('driver_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('dossier_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('version_id')->constrained('dossier_versions')->restrictOnDelete();
            $t->unsignedInteger('milestone');
            $t->string('recipient');
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->text('error')->nullable();
            $t->timestamps();
            $t->unique(['version_id', 'milestone', 'recipient'], 'dossier_reminder_unique');
        });
        foreach (['Khám sức khỏe', 'CCCD', 'Bằng lái / chứng chỉ nghề', 'Thẻ an toàn', 'Bảo hiểm tai nạn / danh sách', 'Hợp đồng lao động', 'Quyết định giao việc', 'Kiểm định / đăng kiểm', 'Bảo hiểm máy / xe'] as $i => $name) {
            $groups = $i === 6 ? ['driver', 'machine', 'vehicle'] : ($i < 7 ? ['driver'] : ['machine', 'vehicle']);
            DB::table('dossier_types')->insert(['name' => $name, 'groups' => json_encode($groups),
                'required_groups' => '[]', 'expiry_mode' => 'review', 'warning_days' => '[30,15,7]', 'sort_order' => $i * 10,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Explicit rollback only on an empty installation. Never discard dossier history.
        if (DB::table('dossier_sources')->exists() || DB::table('dossier_records')->exists()) {
            throw new RuntimeException('Có hồ sơ điện tử: giữ schema và rollback code thay vì xóa lịch sử.');
        }
        foreach (['dossier_reminders', 'dossier_sender_profiles', 'dossier_exports', 'dossier_versions', 'dossier_records', 'dossier_sources', 'dossier_batches', 'dossier_types', 'dossier_jobs', 'dossier_failed_jobs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('machines', fn (Blueprint $t) => $t->dropColumn('dossier_group'));
    }
};
