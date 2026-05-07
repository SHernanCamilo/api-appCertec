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
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            // Campos para tareas recurrentes
            $table->boolean('is_recurring')->default(false)->after('status');
            $table->boolean('is_active')->default(true)->after('is_recurring');
            $table->enum('recurrence_type', [
                'every_minute',
                'every_5_minutes',
                'every_15_minutes',
                'every_30_minutes',
                'hourly',
                'daily',
                'weekly',
                'monthly',
                'custom_days',
                'cron'
            ])->nullable()->after('is_active');
            $table->json('recurrence_value')->nullable()->after('recurrence_type');
            $table->timestamp('last_run_at')->nullable()->after('completed_at');
            $table->timestamp('next_run_at')->nullable()->after('last_run_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'is_recurring',
                'is_active',
                'recurrence_type',
                'recurrence_value',
                'last_run_at',
                'next_run_at'
            ]);
        });
    }
};
