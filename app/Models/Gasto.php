<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Gasto: cada compra o pago registrado en una obra.
 *
 * Borrado = soft delete (decisión del dueño): `delete()` solo llena `deleted_at` y el gasto
 * deja de aparecer en todas las consultas (y de sumar en totales y dashboard). El admin lo
 * consulta con `Gasto::onlyTrashed()` y ve quién lo borró en `eliminado_por`.
 * IMPORTANTE: un UPDATE masivo (`Gasto::where(...)->update()`) también ignora los borrados;
 * si deben incluirse, usa `Gasto::withTrashed()`.
 *
 * Los decimales llegan al front como texto ("1500.00"), igual que en el resto de la API.
 *
 * @package App\Models
 * @property int $obra_id
 * @property int $concepto_id
 * @property int $user_id                quién lo registró
 * @property string|null $cantidad       null en mano de obra/servicios (va en pareja con la unidad)
 * @property string|null $unidad_medida  la de ESTA compra; la del concepto solo es la sugerida
 * @property string $monto_total
 * @property string $tipo_pago
 * @property string|null $qr_payload
 * @property string|null $uuid_sat
 * @property string|null $rfc_emisor
 * @property string|null $url_pdf
 * @property string|null $url_xml
 * @property string|null $uuid_cliente   id generado por el teléfono (evita duplicar reenvíos sin señal)
 * @property \Carbon\Carbon $fecha_gasto
 * @property int|null $actualizado_por
 * @property int|null $eliminado_por
 * @property \Carbon\Carbon|null $deleted_at
 */
class Gasto extends Model
{
    /** @use HasFactory<\Database\Factories\GastoFactory> Permite Modelo::factory() en las pruebas */
    use HasFactory, SoftDeletes;

    // Campos que el controlador asigna desde datos ya validados. `eliminado_por` NO está:
    // se asigna a propósito solo al borrar
    protected $fillable = [
        'obra_id', 'concepto_id', 'user_id',
        'cantidad', 'unidad_medida', 'monto_total', 'tipo_pago',
        'qr_payload', 'uuid_sat', 'rfc_emisor', 'url_pdf', 'url_xml',
        'uuid_cliente', 'fecha_gasto', 'actualizado_por',
    ];

    /**
     * `decimal:2` conserva el texto con 2 decimales (no float, que perdería centavos).
     * `date:Y-m-d` hace que la fecha llegue como "2026-10-23" y no con hora y zona horaria,
     * que en el front podría mostrarse como el día anterior.
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:2',
            'monto_total' => 'decimal:2',
            'fecha_gasto' => 'date:Y-m-d',
        ];
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class);
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(Concepto::class);
    }

    /**
     * Quién registró el gasto.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Quién lo corrigió por última vez (null si nunca se corrigió).
     */
    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    /**
     * Quién lo borró (solo en gastos eliminados).
     */
    public function eliminadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eliminado_por');
    }
}
