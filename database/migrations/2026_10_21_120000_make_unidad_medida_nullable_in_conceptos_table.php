<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vuelve opcional la columna `unidad_medida` de `conceptos` y le quita el valor
 * por defecto "pieza".
 *
 * La unidad del concepto es solo la unidad SUGERIDA que el front precarga en el
 * formulario de gasto; la unidad real de cada compra se guardará en el gasto.
 *
 * Hay conceptos que no tienen unidad: pagos de mano de obra o servicios
 * ("Pago a electrico", "Fontanero Manuel"), donde solo importa el monto. Con el
 * valor por defecto, a "Fontanero Manuel" se le guardaba "pieza", un dato falso.
 *
 * Los conceptos que ya tienen "pieza" se conservan: no se puede saber si alguien
 * la eligió a propósito.
 */
return new class extends Migration
{
    /**
     * Hace la columna `unidad_medida` opcional y elimina el valor por defecto.
     */
    public function up(): void
    {
        Schema::table('conceptos', function (Blueprint $table) {
            // En Laravel 11+ change() redefine la columna completa: al no repetir
            // ->default(), PostgreSQL elimina el valor por defecto
            $table->string('unidad_medida', 50)->nullable()->change();
        });
    }

    /**
     * Restaura la columna `unidad_medida` como estaba: obligatoria y con "pieza" por defecto.
     */
    public function down(): void
    {
        // 1. Los conceptos sin unidad vuelven a "pieza"; si no, la columna no puede volver a ser obligatoria
        DB::table('conceptos')->whereNull('unidad_medida')->update(['unidad_medida' => 'pieza']);

        // 2. Restaurar la columna como estaba: obligatoria y con "pieza" por defecto
        Schema::table('conceptos', function (Blueprint $table) {
            $table->string('unidad_medida', 50)->default('pieza')->nullable(false)->change();
        });
    }
};
