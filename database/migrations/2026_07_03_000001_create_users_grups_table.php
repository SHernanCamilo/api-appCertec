<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users_grups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_user');
            $table->string('tipo', 50);
            $table->string('permiso', 150);
            $table->enum('origen', ['Azure', 'local']);
            $table->timestamps();

            $table->foreign('id_user')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['id_user', 'tipo', 'permiso', 'origen'], 'uq_users_grups_user_tipo_perm_origen');
            $table->index(['id_user', 'tipo']);
            $table->index(['id_user', 'origen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users_grups');
    }
};
