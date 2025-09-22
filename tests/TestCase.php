<?php

namespace Ameax\LaravelChangeDetection\Tests;

use Ameax\LaravelChangeDetection\LaravelChangeDetectionServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Ameax\\LaravelChangeDetection\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelChangeDetectionServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel_change_detection_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        // Load the main package migration
        include_once __DIR__.'/../database/migrations/create_change_detection_tables.php.stub';
        $migration = new class extends \Illuminate\Database\Migrations\Migration
        {
            public function up(): void
            {
                $hashesTable = 'hashes';
                $publishersTable = 'publishers';
                $publishesTable = 'publishes';
                $hashDependentsTable = 'hash_dependents';

                if (! \Illuminate\Support\Facades\Schema::hasTable($hashesTable)) {
                    \Illuminate\Support\Facades\Schema::create($hashesTable, function (\Illuminate\Database\Schema\Blueprint $table) {
                        $table->id();
                        $table->morphs('hashable');
                        $table->string('attribute_hash', 32);
                        $table->string('composite_hash', 32)->nullable();
                        $table->timestamp('deleted_at')->nullable();
                        $table->timestamps();
                        $table->unique(['hashable_type', 'hashable_id']);
                        $table->index('deleted_at');
                    });
                }

                if (! \Illuminate\Support\Facades\Schema::hasTable($publishersTable)) {
                    \Illuminate\Support\Facades\Schema::create($publishersTable, function (\Illuminate\Database\Schema\Blueprint $table) {
                        $table->id();
                        $table->string('name');
                        $table->string('model_type');
                        $table->string('publisher_class');
                        $table->enum('status', ['active', 'inactive'])->default('active');
                        $table->json('config')->nullable();
                        $table->timestamps();
                        $table->index('model_type');
                        $table->index('status');
                        $table->unique('name');
                    });
                }

                if (! \Illuminate\Support\Facades\Schema::hasTable($publishesTable)) {
                    \Illuminate\Support\Facades\Schema::create($publishesTable, function (\Illuminate\Database\Schema\Blueprint $table) use ($hashesTable, $publishersTable) {
                        $table->id();
                        $table->foreignId('hash_id')->nullable()->constrained($hashesTable)->cascadeOnDelete();
                        $table->foreignId('publisher_id')->constrained($publishersTable)->cascadeOnDelete();
                        $table->string('published_hash', 32)->nullable();
                        $table->json('metadata')->nullable();
                        $table->timestamp('published_at')->nullable();
                        $table->enum('status', ['pending', 'dispatched', 'deferred', 'published', 'failed'])->default('pending');
                        $table->unsignedInteger('attempts')->default(0);
                        $table->text('last_error')->nullable();
                        $table->integer('last_response_code')->nullable();
                        $table->enum('error_type', ['validation', 'infrastructure', 'data', 'unknown'])->nullable();
                        $table->timestamp('next_try')->nullable();
                        $table->timestamps();
                        $table->unique(['hash_id', 'publisher_id']);
                        $table->index(['status', 'next_try']);
                    });
                }

                if (! \Illuminate\Support\Facades\Schema::hasTable($hashDependentsTable)) {
                    \Illuminate\Support\Facades\Schema::create($hashDependentsTable, function (\Illuminate\Database\Schema\Blueprint $table) use ($hashesTable) {
                        $table->id();
                        $table->foreignId('hash_id')->constrained($hashesTable)->cascadeOnDelete();
                        $table->string('dependent_model_type');
                        $table->unsignedBigInteger('dependent_model_id');
                        $table->string('relation_name')->nullable();
                        $table->timestamps();
                        $table->unique(['hash_id', 'dependent_model_type', 'dependent_model_id'], 'unique_hash_dependent');
                        $table->index(['dependent_model_type', 'dependent_model_id'], 'dependent_model_index');
                    });
                }
            }
        };

        $migration->up();
    }
}
