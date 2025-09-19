<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishes', function (Blueprint $table) {
            $table->integer('last_response_code')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('publishes', function (Blueprint $table) {
            $table->dropColumn('last_response_code');
        });
    }
};