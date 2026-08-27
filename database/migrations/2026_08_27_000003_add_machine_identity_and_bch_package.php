<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('machines',function(Blueprint $t){$t->string('brand')->nullable()->after('machine_type');$t->string('model_name')->nullable()->after('brand');$t->unsignedSmallInteger('capacity_class')->nullable()->after('model_name');$t->unsignedTinyInteger('vehicle_axles')->nullable()->after('capacity_class');});
  Schema::table('machine_intake_cases',function(Blueprint $t){$t->string('brand')->nullable()->after('machine_type');$t->unsignedSmallInteger('capacity_class')->nullable()->after('model_name');$t->unsignedTinyInteger('vehicle_axles')->nullable()->after('capacity_class');$t->string('bch_email_to')->nullable();$t->string('bch_email_cc')->nullable();$t->string('bch_email_subject')->nullable();$t->text('bch_email_body')->nullable();$t->string('bch_package_path')->nullable();$t->foreignId('email_sent_by')->nullable()->constrained('users')->nullOnDelete();});
 }
 public function down(): void {Schema::table('machine_intake_cases',fn(Blueprint $t)=>$t->dropConstrainedForeignId('email_sent_by'));Schema::table('machine_intake_cases',fn(Blueprint $t)=>$t->dropColumn(['brand','capacity_class','vehicle_axles','bch_email_to','bch_email_cc','bch_email_subject','bch_email_body','bch_package_path']));Schema::table('machines',fn(Blueprint $t)=>$t->dropColumn(['brand','model_name','capacity_class','vehicle_axles']));}
};
