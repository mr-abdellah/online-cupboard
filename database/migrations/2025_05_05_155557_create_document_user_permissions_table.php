<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_user_permissions', function (Blueprint $table) {
            $table->uuid('document_id');
            $table->uuid('user_id');
            $table->enum('permission', ['view', 'edit', 'delete', 'download']);
            $table->timestamps();

            $table->primary(['document_id', 'user_id', 'permission']);
            $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_user_permissions');
    }
};
