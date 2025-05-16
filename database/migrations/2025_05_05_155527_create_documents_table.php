<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type');
            $table->text('ocr')->nullable();
            $table->json('tags')->nullable();
            $table->uuid('binder_id');
            $table->uuid('user_id'); // Added user_id column
            $table->string('path');
            $table->integer('order')->default(0);
            $table->boolean('is_searchable')->default(true);
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            $table->foreign('binder_id')->references('id')->on('binders')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade'); // Foreign key constraint
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};