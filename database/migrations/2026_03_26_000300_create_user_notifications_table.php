<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('hazard_report_id')->nullable()->constrained('hazard_reports')->nullOnDelete();

            $table->string('type');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('status_key')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};

