<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Concepto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de conceptos (lo que se compra o renta en la obra) para las pruebas.
 *
 * Cada prueba crea en una línea el concepto que necesita:
 *
 *   Concepto::factory()->create();              // unidad "pieza" y una categoría nueva
 *   Concepto::factory()->sinUnidad()->create(); // pago de mano de obra o servicio
 *   Concepto::factory()->create(['nombre' => 'Cemento de 50 kg', 'unidad_medida' => 'saco']);
 *
 * `nombre_normalizado` y `palabras_clave` NO se definen aquí: los calcula siempre
 * el modelo al guardar, igual que en producción.
 *
 * @extends Factory<Concepto>
 */
class ConceptoFactory extends Factory
{
    /**
     * Valores por defecto de cada concepto creado.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // unique(): dos conceptos con el mismo nombre chocarían con el índice único de nombre_normalizado
            'nombre' => 'Concepto '.fake()->unique()->words(2, true),
            // Crea también su categoría; si la prueba manda categoria_id, Laravel no la crea
            'categoria_id' => Categoria::factory(),
            'unidad_medida' => 'pieza',
        ];
    }

    /**
     * Concepto sin unidad sugerida, como los pagos de mano de obra o servicios
     * ("Pago a electrico", "Fontanero Manuel"), donde solo importa el monto.
     */
    public function sinUnidad(): static
    {
        return $this->state(fn (array $attributes): array => [
            'unidad_medida' => null,
        ]);
    }
}
