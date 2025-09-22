<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Simple test model table for testing hash functionality
        Schema::create('test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        // Test users table for testing relationships
        Schema::create('test_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        // Test posts table for testing nested relationships
        Schema::create('test_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('test_users')->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        // Test comments table for testing deep relationships
        Schema::create('test_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('test_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('test_users')->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();
        });

        // Test cars table with various column types
        Schema::create('test_cars', function (Blueprint $table) {
            $table->id();
            $table->string('model', 100);                    // String type
            $table->integer('year');                         // Integer type
            $table->decimal('price', 10, 2);                 // Decimal type
            $table->boolean('is_electric')->default(false);  // Boolean type
            $table->json('features')->nullable();            // JSON type
            $table->timestamps();
        });

        // Test Animals table with various column types
        Schema::create('test_animals', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100);                    // String type
            $table->integer('birthday');                         // Integer type
            $table->decimal('group', 10,2);                 // Decimal type
            $table->json('features')->nullable();            // JSON type
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_cars');
        Schema::dropIfExists('test_comments');
        Schema::dropIfExists('test_posts');
        Schema::dropIfExists('test_users');
        Schema::dropIfExists('test_models');
    }
};
