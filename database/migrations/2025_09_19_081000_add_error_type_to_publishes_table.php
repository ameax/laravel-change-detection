<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishes', function (Blueprint $table) {
            $table->enum('error_type', ['validation', 'infrastructure', 'data', 'unknown'])
                  ->nullable()
                  ->after('last_response_code');
        });
    }

    public function down(): void
    {
        Schema::table('publishes', function (Blueprint $table) {
            $table->dropColumn('error_type');
        });
    }
};