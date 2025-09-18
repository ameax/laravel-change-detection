<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('test_articles')->cascadeOnDelete();
            $table->text('content');
            $table->string('author');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_replies');
    }
};
