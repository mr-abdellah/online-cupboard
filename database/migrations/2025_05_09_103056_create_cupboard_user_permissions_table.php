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
        Schema::create('cupboard_user_permissions', function (Blueprint $table) {
            $table->uuid('cupboard_id');
            $table->foreign('cupboard_id')->references('id')->on('cupboards')->onDelete('cascade');

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->enum('permission', ['view', 'edit', 'delete', 'manage'])->default('view');
            $table->timestamps();

            $table->primary(['cupboard_id', 'user_id', 'permission']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cupboard_user_permissions');
    }
};