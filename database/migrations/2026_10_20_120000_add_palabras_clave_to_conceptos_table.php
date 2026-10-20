<?php

use App\Support\NormalizadorTexto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `palabras_clave` a la tabla `conceptos`.
 *
 * Guarda la firma de {@see NormalizadorTexto::palabrasClave()}: "Saco de cemento
 * 25 kg" y "Cemento saco 25 kg" → "25kg cemento saco".
 *
 * NO es única: varios conceptos pueden compartirla. Sirve para detectar POSIBLES
 * duplicados (respuesta 409 al crear un concepto y reporte del administrador);
 * una persona decide si fusionarlos. El índice evita recorrer toda la tabla en
 * esas búsquedas.
 */
return new class extends Migration
{
    /**
     * Agrega la columna `palabras_clave` y rellena los datos existentes.
     */
    public function up(): void
    {
        // 1. Columna nueva, temporalmente opcional para poder rellenarla
        Schema::table('conceptos', function (Blueprint $table) {
            $table->string('palabras_clave', 200)->nullable()->after('nombre_normalizado');
        });

        // 2. Rellenar los conceptos existentes con la firma de palabras clave
        foreach (DB::table('conceptos')->orderBy('id')->lazyById() as $fila) {
            DB::table('conceptos')->where('id', $fila->id)->update([
                'palabras_clave' => NormalizadorTexto::palabrasClave($fila->nombre),
            ]);
        }

        // 3. Hacer la columna obligatoria y crear el índice
        Schema::table('conceptos', function (Blueprint $table) {
            $table->string('palabras_clave', 200)->nullable(false)->change();
            $table->index('palabras_clave');
        });
    }

    /**
     * Elimina la columna `palabras_clave` y el índice asociado.
     */
    public function down(): void
    {
        // 1. Eliminar el índice y la columna
        Schema::table('conceptos', function (Blueprint $table) {
            $table->dropIndex(['palabras_clave']);
            $table->dropColumn('palabras_clave');
        });
    }
};
