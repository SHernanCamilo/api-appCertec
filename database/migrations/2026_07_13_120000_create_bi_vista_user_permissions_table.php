<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bi_vista_user_permissions')) {
            return;
        }

        Schema::create('bi_vista_user_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bi_vista_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('bi_vista_id')->references('id')->on('bi_vistas')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['bi_vista_id', 'user_id'], 'bi_vista_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_vista_user_permissions');
    }
};
