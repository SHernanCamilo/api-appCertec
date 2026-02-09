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
        Schema::create('glpiFrac_formulario_c', function (Blueprint $table) {
            $table->id();
            $table->integer('glpi_form_id')->nullable()->comment('ID del formulario en GLPI');
            $table->integer('glpi_ticket_id')->nullable()->comment('ID del ticket en GLPI');
            $table->string('glpi_placa_activo', 100)->nullable()->comment('Placa del activo en GLPI');
            $table->integer('glpi_user_id')->nullable()->comment('ID del usuario en GLPI');
            $table->text('glpi_observacion')->nullable()->comment('Observaciones del formulario');
            $table->timestamps();
            
            $table->index('glpi_form_id');
            $table->index('glpi_ticket_id');
            $table->index('glpi_placa_activo');
            $table->index('glpi_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('glpiFrac_formulario_c');
    }
};
