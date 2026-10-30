<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_types', fn (Blueprint $t) => $t->string('ai_key', 64)->nullable()->unique());
        Schema::table('dossier_versions', function (Blueprint $t) {
            $t->json('provenance')->nullable();
            $t->date('health_signed_on')->nullable();
            $t->date('renewal_on')->nullable();
        });
        Schema::create('dossier_ai_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('source_id')->constrained('dossier_sources')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('cache_key', 64)->unique();
            $t->string('pipeline');
            $t->unsignedInteger('source_revision');
            $t->json('context');
            $t->string('status')->index();
            $t->boolean('paused')->default(false);
            $t->unsignedInteger('generation')->default(0);
            $t->unsignedInteger('group_attempts')->default(0);
            $t->json('group_result')->nullable();
            $t->json('group_usage')->nullable();
            $t->unsignedInteger('group_ms')->nullable();
            $t->timestamp('available_at')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
        });
        Schema::create('dossier_ai_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->constrained('dossier_ai_runs')->restrictOnDelete();
            $t->unsignedInteger('page');
            $t->unsignedInteger('dpi')->default(200);
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('image_sha256', 64)->nullable();
            $t->json('result')->nullable();
            $t->json('raw')->nullable();
            $t->json('usage')->nullable();
            $t->unsignedInteger('elapsed_ms')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
            $t->unique(['run_id', 'page']);
        });
        Schema::create('dossier_ai_proposals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->constrained('dossier_ai_runs')->restrictOnDelete();
            $t->unsignedInteger('generation');
            $t->unsignedInteger('position');
            $t->json('data');
            $t->json('issues');
            $t->json('reviewed')->nullable();
            $t->foreignId('version_id')->nullable()->constrained('dossier_versions')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['run_id', 'generation', 'position']);
        });
        Schema::create('dossier_ai_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('type_id')->constrained('dossier_types')->restrictOnDelete();
            $t->string('version');
            $t->string('anchor');
            $t->unsignedInteger('months');
            $t->text('scope');
            $t->string('basis');
            $t->date('effective_from');
            $t->boolean('enabled')->default(false);
            $t->timestamps();
            $t->unique(['type_id', 'version']);
        });
        Schema::create('dossier_ai_control', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->uuid('token')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('next_call_at')->nullable();
        });
        DB::table('dossier_ai_control')->insert(['id' => 1]);
        $old = ['Khám sức khỏe' => 'health', 'CCCD' => 'identity', 'Bằng lái / chứng chỉ nghề' => 'qualification', 'Thẻ an toàn' => 'safety', 'Bảo hiểm tai nạn / danh sách' => 'bic_personal', 'Hợp đồng lao động' => 'employment', 'Quyết định giao việc' => 'assignment', 'Bảo hiểm máy / xe' => 'asset_insurance'];
        foreach ($old as $name => $key) {
            $row = DB::table('dossier_types')->where('name', $name)->first();
            if ($row) {
                DB::table('dossier_types')->where('id', $row->id)->update(['ai_key' => $key]);
            }
        }
        foreach (['registration' => ['Đăng ký xe', 'vehicle'], 'vehicle_inspection' => ['Đăng kiểm xe', 'vehicle'], 'machine_inspection' => ['Kiểm định máy', 'machine']] as $key => [$name,$group]) {
            DB::table('dossier_types')->insert(['name' => $name, 'ai_key' => $key, 'groups' => json_encode([$group]), 'required_groups' => '[]', 'expiry_mode' => 'review', 'warning_days' => '[30,15,7]', 'sort_order' => 80, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['health' => ['health_signed_on', 12, 'Giấy khám sức khỏe thông thường; không thay lịch khám nghề nghiệp'], 'safety' => ['issued_on', 24, 'Thẻ an toàn đúng loại, cần người dùng xác nhận phạm vi'], 'machine_inspection' => ['issued_on', 12, 'Kiểm định máy trong phạm vi nghiệp vụ đã xác nhận'], 'bic_personal' => ['effective_at', 12, 'Bảo hiểm tai nạn con người BIC, đã đối chiếu danh sách/phạm vi'], 'asset_insurance' => ['effective_at', 12, 'Bảo hiểm tài sản, chỉ khi không có hạn in trên giấy']] as $key => [$anchor,$months,$scope]) {
            $type = DB::table('dossier_types')->where('ai_key', $key)->value('id');
            if ($type) {
                DB::table('dossier_ai_rules')->insert(['type_id' => $type, 'version' => 'business-2026-10-30-v1', 'anchor' => $anchor, 'months' => $months, 'scope' => $scope, 'basis' => 'Mốc nghiệp vụ người dùng cung cấp; không phải kết luận pháp lý', 'effective_from' => '2026-10-30', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Giữ dữ liệu đề xuất/audit; rollback code thay vì xóa lịch sử AI.');
    }
};
