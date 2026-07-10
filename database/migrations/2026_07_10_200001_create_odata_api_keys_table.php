<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odata_api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name', 100)->comment('Nombre descriptivo: "Mi Excel oficina"');
            $table->string('key_hash', 128)->comment('SHA-256 del API Key');
            $table->string('key_prefix', 12)->comment('Primeros chars para identificar: jade_pk_a1b2...');
            $table->boolean('active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->integer('use_count')->default(0);
            $table->string('ip_last_used', 45)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('key_hash');
            $table->index(['user_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odata_api_keys');
    }
};
