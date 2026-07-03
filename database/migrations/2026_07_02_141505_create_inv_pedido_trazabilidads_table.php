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
        Schema::create('inv_pedidos_trazabilidad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('inv_pedidos')->onDelete('cascade');
            $table->string('estado');
            $table->text('comentarios')->nullable();
            $table->foreignId('cambiado_por')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_pedidos_trazabilidad');
    }
};
