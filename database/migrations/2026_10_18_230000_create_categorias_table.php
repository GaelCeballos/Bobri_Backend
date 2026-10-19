<?php

use App\Support\NormalizadorTexto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea el catálogo de categorías de gasto.
 *
 * Las categorías dejan de ser un enum fijo en `conceptos` y pasan a ser un
 * catálogo que crece cuando un usuario escribe una categoría que no existía.
 * Para que no haya duplicados escritos de distinta forma ("Herramienta menor"
 * vs "HERRAMIENTA  menor"), cada fila guarda una huella única calculada con
 * {@see NormalizadorTexto::normalizar()}.
 *
 * Las 5 categorías del enum anterior se insertan aquí porque la siguiente
 * migración convierte `conceptos.categoria` (enum) en `conceptos.categoria_id`
 * (llave foránea) y necesita que ya existan.
 */
return new class extends Migration
{
    /**
     * Crea la tabla `categorias` e inserta las categorías iniciales.
     */
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            // Nombre tal como lo escribió el usuario; es el que se muestra en pantalla
            $table->string('nombre', 100);
            // Huella para comparar; única para impedir duplicados incluso con peticiones simultáneas
            $table->string('nombre_normalizado', 100)->unique();
            $table->timestamps();
        });

        $categoriasIniciales = [
            'Materiales',
            'Mano de Obra',
            'Equipo/Herramienta',
            'Combustible',
            'Varios',
        ];

        // Se usa el query builder (no modelos Eloquent) para que la migración no
        // dependa de cómo evolucione el modelo en el futuro
        $ahora = now();

        DB::table('categorias')->insert(array_map(
            fn (string $nombre): array => [
                'nombre' => $nombre,
                'nombre_normalizado' => NormalizadorTexto::normalizar($nombre),
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            $categoriasIniciales,
        ));
    }

    /**
     * Elimina la tabla `categorias` (y con ella las categorías iniciales).
     */
    public function down(): void
    {
        Schema::dropIfExists('categorias');
    }
};
