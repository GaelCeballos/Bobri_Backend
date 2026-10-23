<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impide borrar una obra que tiene gastos.
 *
 * La llave foránea `gastos.obra_id` se creó con `cascadeOnDelete()`: borrar una obra
 * borraba en silencio todos sus gastos, y con ellos el historial contable de la obra.
 * Las obras no se borran, se marcan `Finalizada` (decisión del dueño), así que la
 * llave pasa a `restrictOnDelete()`: la base de datos rechaza el borrado aunque algún
 * día alguien lo intente desde código o directamente en SQL.
 */
return new class extends Migration
{
    /**
     * Cambia la llave foránea de "borrar en cascada" a "impedir el borrado".
     */
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            // Una llave foránea no se puede modificar: se elimina y se vuelve a crear
            $table->dropForeign(['obra_id']);
            $table->foreign('obra_id')->references('id')->on('obras')->restrictOnDelete();
        });
    }

    /**
     * Regresa al comportamiento anterior (borrar la obra borra sus gastos).
     */
    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->dropForeign(['obra_id']);
            $table->foreign('obra_id')->references('id')->on('obras')->cascadeOnDelete();
        });
    }
};
