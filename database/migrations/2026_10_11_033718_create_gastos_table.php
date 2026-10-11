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
        Schema::create('gastos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obra_id')->constrained('obras')->cascadeOnDelete();
            $table->foreignId('concepto_id')->constrained('conceptos')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->decimal('cantidad', 10 , 2);
            $table->decimal('monto_total',12, 2);
            $table->enum('tipo_pago', ['Efectivo', 'Tarjeta', 'Transferencia']);

            $table->text('qr_payload')->nullable();
            $table->uuid('uuid_sat')->nullable()->unique();
            $table->string('url_pdf', 500)->nullable();

            $table->date('fecha_gasto')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gastos');
    }
};
