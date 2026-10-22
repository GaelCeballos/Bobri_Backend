<?php

namespace Database\Factories;

use App\Models\Categoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de categorías de gasto para las pruebas.
 *
 * Cada prueba crea en una línea la categoría que necesita:
 *
 *   Categoria::factory()->create();                                  // nombre aleatorio
 *   Categoria::factory()->create(['nombre' => 'Herramienta menor']); // nombre específico
 *
 * `nombre_normalizado` NO se define aquí: lo calcula siempre el modelo (trait
 * TieneNombreNormalizado) al guardar, igual que en producción.
 *
 * La migración ya siembra las 5 categorías iniciales (Materiales, Mano de Obra,
 * Equipo/Herramienta, Combustible, Varios); por eso los nombres de la fábrica
 * empiezan con "Categoría", para no chocar con ellas.
 *
 * @extends Factory<Categoria>
 */
class CategoriaFactory extends Factory
{
    /**
     * Valores por defecto de cada categoría creada.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // unique(): dos categorías con el mismo nombre chocarían con el índice único de nombre_normalizado
            'nombre' => 'Categoría '.fake()->unique()->word(),
        ];
    }
}
