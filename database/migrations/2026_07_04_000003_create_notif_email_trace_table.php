<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notif_email_trace', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_log_id')
                  ->constrained('notif_email_logs')
                  ->cascadeOnDelete();
            $table->string('event_type', 50);   // PROGRAMADO, ENVIADO, VERIFICANDO, ENTREGADO, REBOTADO, ERROR
            $table->string('event_status', 20); // PENDING, SUCCESS, WARNING, ERROR
            $table->text('event_message');
            $table->text('event_details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('email_log_id', 'idx_email_log');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notif_email_trace');
    }
};
