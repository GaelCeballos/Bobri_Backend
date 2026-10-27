<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de un ticket: un producto leído de la foto ("LAM GALV C26 3.05").
 *
 * Antes de validar es solo una propuesta que el Administrador corrige: el concepto puede
 * ser uno existente (`concepto_id`) o uno nuevo que se creará al validar (`concepto_nombre`),
 * y cantidad, unidad o monto pueden venir vacíos si no se leyeron. Al validar se exigen y el
 * renglón se convierte en un gasto (`gasto_id`).
 *
 * @package App\Models
 * @property int $ticket_id
 * @property int $orden  posición en el ticket
 * @property string|null $descripcion_leida  null si el admin lo agregó a mano
 * @property int|null $concepto_id
 * @property string|null $concepto_nombre  concepto NUEVO a crear al validar
 * @property string|null $cantidad
 * @property string|null $unidad_medida
 * @property string|null $monto
 * @property int|null $gasto_id  gasto creado al validar
 */
class TicketRenglon extends Model
{
    // Laravel inferiría "ticket_renglons" (pluraliza en inglés)
    protected $table = 'ticket_renglones';

    // Campos que el controlador asigna desde datos ya validados. `gasto_id` NO está:
    // solo se asigna al validar
    protected $fillable = [
        'orden', 'descripcion_leida', 'concepto_id', 'concepto_nombre',
        'cantidad', 'unidad_medida', 'monto',
    ];

    /**
     * `decimal:2` conserva el texto con 2 decimales (no float, que perdería centavos).
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:2',
            'monto' => 'decimal:2',
        ];
    }

    /**
     * Ticket al que pertenece.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Concepto sugerido o elegido; null si es nuevo o no se reconoció.
     */
    public function concepto(): BelongsTo
    {
        return $this->belongsTo(Concepto::class);
    }

    /**
     * Gasto creado al validar.
     */
    public function gasto(): BelongsTo
    {
        return $this->belongsTo(Gasto::class);
    }
}
