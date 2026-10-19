<?php

use App\Support\NormalizadorTexto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte `conceptos` en un catálogo sin duplicados ligado a `categorias`.
 *
 * Cambios:
 *  - `categoria` (enum fijo de 5 valores) → `categoria_id` (llave foránea a
 *    `categorias`), para que los usuarios puedan agregar categorías nuevas.
 *  - Nueva columna `nombre_normalizado` (única), calculada con
 *    {@see NormalizadorTexto::normalizar()}, para que "MORTERO Cemex 50kg" y
 *    "mortero CEMEX 50 kilos" no puedan existir como dos conceptos distintos.
 *
 * Los conceptos que ya existan se convierten automáticamente. En PostgreSQL
 * la migración corre dentro de una transacción: si algo falla (p. ej. hay
 * conceptos duplicados), no queda ningún cambio a medias.
 */
return new class extends Migration
{
    /** Valores del enum `conceptos.categoria` que existía antes de esta migración. */
    private const CATEGORIAS_ENUM = [
        'Materiales',
        'Mano de Obra',
        'Equipo/Herramienta',
        'Combustible',
        'Varios',
    ];

    /**
     * Agrega `nombre_normalizado` y `categoria_id`, convierte los datos
     * existentes y elimina el enum `categoria`.
     *
     * @throws RuntimeException si un concepto tiene una categoría desconocida o
     *                          si hay conceptos que normalizados quedan iguales.
     */
    public function up(): void
    {
        // 1. Columnas nuevas, temporalmente opcionales para poder rellenarlas
        Schema::table('conceptos', function (Blueprint $table) {
            $table->string('nombre_normalizado', 200)->nullable()->after('nombre');
            $table->foreignId('categoria_id')->nullable()->after('nombre_normalizado')
                ->constrained('categorias')
                // No se puede borrar una categoría que todavía tenga conceptos
                ->restrictOnDelete();
            // PostgreSQL no indexa solas las llaves foráneas; las gráficas agrupan por categoría
            $table->index('categoria_id');
        });

        // 2. Rellenar los conceptos existentes ("Materiales" → id de esa categoría)
        $categorias = DB::table('categorias')->pluck('id', 'nombre');

        // lazyById() lee por bloques para no cargar toda la tabla en memoria
        foreach (DB::table('conceptos')->orderBy('id')->lazyById() as $fila) {
            $categoriaId = $categorias[$fila->categoria]
                ?? throw new RuntimeException(
                    "El concepto #{$fila->id} tiene la categoría desconocida \"{$fila->categoria}\"."
                );

            DB::table('conceptos')->where('id', $fila->id)->update([
                'nombre_normalizado' => NormalizadorTexto::normalizar($fila->nombre),
                'categoria_id' => $categoriaId,
            ]);
        }

        // 3. Antes de exigir unicidad, avisar con un mensaje claro si ya hay duplicados
        $duplicados = DB::table('conceptos')
            ->select('nombre_normalizado')
            ->groupBy('nombre_normalizado')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('nombre_normalizado');

        if ($duplicados->isNotEmpty()) {
            throw new RuntimeException(
                'Hay conceptos que se escriben distinto pero son el mismo: '.$duplicados->implode(', ')
                .'. Unifícalos manualmente (y sus gastos) antes de ejecutar esta migración.'
            );
        }

        // 4. Quitar el enum y volver obligatorias las columnas nuevas
        Schema::table('conceptos', function (Blueprint $table) {
            $table->dropColumn('categoria');
            $table->string('nombre_normalizado', 200)->nullable(false)->change();
            $table->unique('nombre_normalizado');
            $table->foreignId('categoria_id')->nullable(false)->change();
        });
    }

    /**
     * Restaura el enum `categoria` y elimina `categoria_id` y `nombre_normalizado`.
     *
     * Las categorías creadas por los usuarios no existían en el enum, así que los
     * conceptos que las usen quedan como "Varios" (se pierde ese detalle).
     *
     * Nota: Laravel no puede modificar una columna enum en PostgreSQL con
     * `->change()`, por eso el enum se crea ya obligatorio con valor por defecto
     * "Varios" y luego se corrige fila por fila. Única diferencia con la tabla
     * original: la columna conserva ese valor por defecto.
     */
    public function down(): void
    {
        // 1. Enum de vuelta; el default permite crearlo NOT NULL aunque ya haya filas
        Schema::table('conceptos', function (Blueprint $table) {
            $table->enum('categoria', self::CATEGORIAS_ENUM)->default('Varios')->after('nombre');
        });

        // 2. id de categoría → texto del enum (una sola consulta para todas las categorías)
        $nombresCategorias = DB::table('categorias')->pluck('nombre', 'id');

        foreach (DB::table('conceptos')->orderBy('id')->lazyById() as $fila) {
            $nombre = $nombresCategorias[$fila->categoria_id] ?? null;

            DB::table('conceptos')->where('id', $fila->id)->update([
                'categoria' => in_array($nombre, self::CATEGORIAS_ENUM, true) ? $nombre : 'Varios',
            ]);
        }

        // 3. Fuera las columnas nuevas (primero índices y llave foránea)
        Schema::table('conceptos', function (Blueprint $table) {
            $table->dropUnique(['nombre_normalizado']);
            $table->dropForeign(['categoria_id']);
            $table->dropIndex(['categoria_id']);
            $table->dropColumn(['categoria_id', 'nombre_normalizado']);
        });
    }
};
