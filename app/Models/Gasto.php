<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class Gasto
 *
 * @package App\Models
 * @property int $obra_id
 * @property int $concepto_id
 * @property int $user_id
 * @property float $cantidad
 * @property float $monto_total
 * @property string $tipo_pago
 * @property string|null $qr_payload
 * @property string|null $uuid_sat
 * @property string|null $url_pdf
 * @property string|null $url_xml
 * @property \Carbon\Carbon|null $fecha_gasto
 */
class Gasto extends Model
{   
    //  Proteger los campos que se pueden asignar masivamente
    protected $fillable = [
        'obra_id','concepto_id', 'user_id',
        'cantidad', 'monto_total', 'tipo_pago',
        'qr_payload','uuid_sat','url_pdf','url_xml', 'fecha_gasto'
    ];

    // Definir la relación con el modelo Obra
    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class);
    }

    // Definir la relación con el modelo Concepto
    public function concepto(): BelongsTo
    {
        return $this->belongsTo(Concepto::class);
    }
    // Definir la relación con el modelo User
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
