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
            $table->decimal('group', 10, 2);                 // Decimal type
            $table->json('features')->nullable();            // JSON type
            $table->float('weight')->nullable();             // Float type for animal weight
            $table->timestamps();
        });

        // Weather Stations table - Main monitoring station
        Schema::create('test_weather_stations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('status')->default('active');
            $table->boolean('is_operational')->default(true);
            $table->timestamps();
        });

        // Windvanes table - Wind direction sensors
        Schema::create('test_windvanes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weather_station_id')->constrained('test_weather_stations')->cascadeOnDelete();
            $table->decimal('direction', 5, 2);             // 0-359.99 degrees
            $table->decimal('accuracy', 5, 2);              // accuracy percentage
            $table->date('calibration_date');
            $table->timestamps();
        });

        // Anemometers table - Wind speed sensors
        Schema::create('test_anemometers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weather_station_id')->constrained('test_weather_stations')->cascadeOnDelete();
            $table->decimal('wind_speed', 5, 2);            // m/s
            $table->decimal('max_speed', 5, 2);             // maximum recorded speed
            $table->string('sensor_type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_anemometers');
        Schema::dropIfExists('test_windvanes');
        Schema::dropIfExists('test_weather_stations');
        Schema::dropIfExists('test_animals');
        Schema::dropIfExists('test_cars');
        Schema::dropIfExists('test_comments');
        Schema::dropIfExists('test_posts');
        Schema::dropIfExists('test_users');
        Schema::dropIfExists('test_models');
    }
};
