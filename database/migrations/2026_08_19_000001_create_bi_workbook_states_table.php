<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_workbook_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('schema_name', 20);
            $table->string('view_name', 150);
            $table->string('name', 100)->default('default');
            $table->json('state')->comment('JSON: sheets, filters, column widths/order, pivot config, zoom');
            $table->boolean('is_default')->default(true);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'schema_name', 'view_name', 'name'], 'bi_wb_user_view_name_unique');
            $table->index(['user_id', 'schema_name'], 'bi_wb_user_schema_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_workbook_states');
    }
};
