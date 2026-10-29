<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos de posible duplicado que el Administrador descartó ("No es duplicado").
 *
 * Al revisar un ticket por foto, {@see \App\Support\PosiblesDuplicados} avisa de gastos
 * capturados a mano (misma obra, mismo día, mismo monto) que podrían ser la misma compra.
 * Si el admin decide que no lo son, se guarda aquí el par ticket + gasto para:
 * - que el aviso no vuelva a salir (el ticket puede revisarse en varias sesiones o por otro admin);
 * - monitorear quién descartó cada aviso y cuándo (decisión del dueño, 2026-10-29).
 *
 * Los registros se conservan siempre: son historial, no se limpian con el tiempo (< 1 MB en 10 años).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicados_descartados', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete en las tres: tickets y usuarios nunca se borran y los gastos usan
            // soft delete. Si alguien intentara borrar uno a mano, la BD lo impide en lugar de
            // perder en silencio el historial de quién descartó el aviso
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            // Gasto manual que el admin revisó y NO es la misma compra que el ticket
            $table->foreignId('gasto_id')->constrained('gastos')->restrictOnDelete();
            // Administrador que lo decidió
            $table->foreignId('descartado_por')->constrained('users')->restrictOnDelete();
            // created_at = cuándo se descartó
            $table->timestamps();

            // El mismo par no se descarta dos veces (si dos admins pulsan a la vez, el segundo
            // no duplica la fila). Empieza por ticket_id, así que también sirve para buscar los
            // descartes de un ticket: no hace falta otro índice en esa columna
            $table->unique(['ticket_id', 'gasto_id']);
            // Monitoreo: "¿qué avisos descartó este administrador?"
            $table->index('descartado_por');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicados_descartados');
    }
};
