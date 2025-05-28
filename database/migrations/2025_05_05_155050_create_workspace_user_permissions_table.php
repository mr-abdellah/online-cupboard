<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWorkspaceUserPermissionsTable extends Migration
{
    public function up()
    {
        Schema::create('workspace_user_permissions', function (Blueprint $table) {
            $table->uuid('workspace_id')->nullable();
            $table->uuid('user_id');
            $table->string('permission');
            $table->timestamps();

            $table->primary(['workspace_id', 'user_id', 'permission'], 'wup_primary');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['user_id', 'permission']);
            $table->index(['workspace_id', 'permission']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('workspace_user_permissions');
    }
}
