<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        $codes = collect(['VINCONS', 'VINALPHA', 'SGC'])
            ->merge(DB::table('machines')->distinct()->pluck('company'))
            ->merge(DB::table('machine_intake_cases')->whereNotNull('company')->distinct()->pluck('company'))
            ->filter(fn ($code) => $code !== null)->unique();
        foreach ($codes as $code) {
            DB::table('companies')->insert(['code' => $code, 'name' => $code, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('machines', function (Blueprint $table) {
            $table->string('company', 20)->change();
        });
        Schema::table('machines', function (Blueprint $table) {
            $table->foreign('company')->references('code')->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('machine_intake_cases', function (Blueprint $table) {
            $table->foreign('company')->references('code')->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        // Keep the widened column: rolling back must not discard SGC or newer company codes.
        Schema::table('machine_intake_cases', fn (Blueprint $table) => $table->dropForeign(['company']));
        Schema::table('machines', fn (Blueprint $table) => $table->dropForeign(['company']));
        Schema::dropIfExists('companies');
    }
};
