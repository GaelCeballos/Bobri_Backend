<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Obra;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fábrica de tickets por foto para las pruebas.
 *
 *   Ticket::factory()->create();                // recién subido: procesando, con foto
 *   Ticket::factory()->porRevisar()->create();  // ya leído, esperando al admin
 *
 * La foto no se crea aquí: `ruta_foto` apunta a una ruta de ejemplo. Las pruebas que
 * necesiten el archivo usan `Storage::fake('tickets')` y lo suben ellas.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Valores por defecto de cada ticket creado.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Crea también la obra, el trabajador y usa una categoría sembrada (Materiales)
            'obra_id' => Obra::factory(),
            'user_id' => User::factory(),
            'categoria_id' => fn () => Categoria::where('nombre', 'Materiales')->value('id'),
            'tipo_pago' => fake()->randomElement(['Efectivo', 'Tarjeta', 'Transferencia']),
            'uuid_cliente' => (string) Str::uuid(),
            'fecha_captura' => now()->toDateString(),
            // Ruta dentro del disco `tickets` (año/mes/uuid.webp), igual que FotoTicket::guardar
            'ruta_foto' => now()->format('Y/m').'/'.Str::uuid().'.webp',
            'estado' => Ticket::PROCESANDO,
        ];
    }

    /**
     * Ticket ya leído, en la bandeja del admin.
     */
    public function porRevisar(): static
    {
        return $this->state(fn (array $attributes): array => [
            'estado' => Ticket::POR_REVISAR,
        ]);
    }
}
