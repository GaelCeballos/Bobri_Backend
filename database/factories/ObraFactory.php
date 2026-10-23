<?php

namespace Database\Factories;

use App\Models\Obra;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de obras (proyectos de construcción con un importe de contrato) para las pruebas.
 *
 * Cada prueba crea en una línea la obra que necesita:
 *
 *   Obra::factory()->create();               // obra Activa
 *   Obra::factory()->finalizada()->create(); // obra terminada
 *
 * @extends Factory<Obra>
 */
class ObraFactory extends Factory
{
    /**
     * Valores por defecto de cada obra creada.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // unique(): nombres distintos para distinguir las obras en las pruebas
            'nombre' => 'Obra '.fake()->unique()->streetName(),
            'cliente' => fake()->company(),
            'ubicacion' => fake()->city(),
            // decimal(12,2) en la BD; la API lo devuelve como texto ("1500000.00")
            'importe_contrato' => fake()->randomFloat(2, 100000, 5000000),
            'estado' => 'Activa',
        ];
    }

    /**
     * Obra terminada; el front solo ofrece obras Activa al capturar gastos.
     */
    public function finalizada(): static
    {
        return $this->state(fn (array $attributes): array => [
            'estado' => 'Finalizada',
        ]);
    }
}
