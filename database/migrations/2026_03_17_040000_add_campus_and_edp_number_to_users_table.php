<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Registration fields required by the client
            $table->string('edp_number', 64)->nullable()->after('email');
            $table->string('campus', 255)->nullable()->after('edp_number');

            $table->index(['campus']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['campus']);
            $table->dropColumn(['edp_number', 'campus']);
        });
    }
};

