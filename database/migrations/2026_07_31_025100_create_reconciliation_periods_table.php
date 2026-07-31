<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['WEEKLY', 'MONTHLY']);
            $table->date('date_from');
            $table->date('date_to');
            $table->enum('status', ['DRAFT', 'GENERATED', 'REVIEWING', 'CONFIRMED', 'EXPORTED'])
                ->default('DRAFT');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'date_from', 'date_to']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_periods');
    }
};
