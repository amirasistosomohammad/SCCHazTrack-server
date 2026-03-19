<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('reporter')->after('password');
            $table->string('department')->nullable()->after('role');
            $table->string('phone', 32)->nullable()->after('department');
            $table->boolean('is_active')->default(true)->after('phone');

            $table->index(['role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'is_active']);
            $table->dropColumn(['role', 'department', 'phone', 'is_active']);
        });
    }
};

