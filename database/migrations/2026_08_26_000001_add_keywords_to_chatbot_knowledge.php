<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campos de keywords y categoría para búsqueda inteligente del ChatBot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_knowledge_views', function (Blueprint $table) {
            $table->string('categoria', 50)->nullable()->after('notas_negocio')
                ->comment('Categoría: censo, facturacion, inventario, cartera, urgencias, etc.');
            $table->text('keywords')->nullable()->after('categoria')
                ->comment('Keywords de búsqueda generados de columnas y nombre');
            $table->unsignedSmallInteger('relevancia')->default(50)->after('keywords')
                ->comment('Relevancia 1-100: vistas más usadas/importantes primero');
            $table->timestamp('columnas_sync_at')->nullable()->after('relevancia')
                ->comment('Última vez que se sincronizaron las columnas desde Graph-Fabric');
        });

        // Agregar índice FULLTEXT para búsqueda
        Schema::table('chatbot_knowledge_views', function (Blueprint $table) {
            $table->fullText(['descripcion', 'keywords', 'view_name'], 'ft_chatbot_search');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_knowledge_views', function (Blueprint $table) {
            $table->dropFullText('ft_chatbot_search');
            $table->dropColumn(['categoria', 'keywords', 'relevancia', 'columnas_sync_at']);
        });
    }
};
