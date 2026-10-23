<?php

namespace Database\Factories;

use App\Models\Concepto;
use App\Models\Gasto;
use App\Models\Obra;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de gastos (cada compra o pago registrado en una obra) para las pruebas.
 *
 * Cada prueba crea en una línea el gasto que necesita:
 *
 *   Gasto::factory()->create();                                     // crea también su obra, concepto y trabajador
 *   Gasto::factory()->count(3)->create(['concepto_id' => $concepto->id]);
 *
 * `qr_payload`, `uuid_sat` y `url_pdf` NO se definen aquí: son opcionales (gastos
 * capturados a mano, sin factura).
 *
 * @extends Factory<Gasto>
 */
class GastoFactory extends Factory
{
    /**
     * Valores por defecto de cada gasto creado.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Crea también la obra, el concepto y el trabajador; si la prueba manda el id,
            // Laravel no los crea
            'obra_id' => Obra::factory(),
            'concepto_id' => Concepto::factory(),
            'user_id' => User::factory(),
            'cantidad' => fake()->randomFloat(2, 1, 100),
            // Cantidad y unidad van en pareja (restricción gastos_cantidad_unidad_en_pareja)
            'unidad_medida' => 'pieza',
            'monto_total' => fake()->randomFloat(2, 100, 50000),
            'tipo_pago' => fake()->randomElement(['Efectivo', 'Tarjeta', 'Transferencia']),
            'fecha_gasto' => fake()->dateTimeBetween('-30 days')->format('Y-m-d'),
        ];
    }
}
