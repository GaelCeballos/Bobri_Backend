<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ticket: una compra de varios productos capturada con UNA foto del ticket.
 *
 * Estados (columna `estado`):
 *   procesando  → la foto se subió y una tarea en cola está leyendo los productos.
 *   por_revisar → ya se leyó; el Administrador corrige los renglones viendo la foto.
 *   validado    → cada renglón se convirtió en un gasto (ver `gastos.ticket_id`).
 *   rechazado   → el admin lo rechazó (`motivo_rechazo`) y la foto se borró.
 *   error       → la lectura falló (`error_lectura`); el admin puede capturar los renglones a
 *                 mano o rechazarlo.
 *
 * Mientras no se valida no cuenta en el dashboard: sus gastos aún no existen.
 * La foto NO está en la base de datos: `ruta_foto` es la ruta en el disco `tickets`.
 *
 * @package App\Models
 * @property int $obra_id
 * @property int $user_id  quién tomó la foto (será el user_id de los gastos)
 * @property int $categoria_id  para los conceptos NUEVOS
 * @property string $tipo_pago
 * @property string $uuid_cliente
 * @property \Carbon\Carbon $fecha_captura  día en que se tomó la foto (según el teléfono)
 * @property string|null $ruta_foto  null si se rechazó
 * @property string $estado
 * @property \Carbon\Carbon|null $fecha_compra
 * @property string|null $proveedor
 * @property string|null $rfc_emisor
 * @property string|null $total
 * @property string|null $qr_payload
 * @property string|null $uuid_sat
 * @property string|null $url_pdf
 * @property string|null $url_xml
 * @property string|null $error_lectura
 * @property string|null $motivo_rechazo
 * @property int|null $revisado_por  admin que validó o rechazó
 * @property \Carbon\Carbon|null $revisado_en
 */
class Ticket extends Model
{
    /** @use HasFactory<\Database\Factories\TicketFactory> Permite Modelo::factory() en las pruebas */
    use HasFactory;

    // Valores de `estado`: usar las constantes evita errores de dedo ("por-revisar")
    public const PROCESANDO = 'procesando';
    public const POR_REVISAR = 'por_revisar';
    public const VALIDADO = 'validado';
    public const RECHAZADO = 'rechazado';
    public const ERROR = 'error';

    // Estados en los que el admin todavía puede corregir, validar o rechazar
    public const EDITABLES = [self::POR_REVISAR, self::ERROR];

    // Campos que el controlador asigna desde datos ya validados. `estado`, `ruta_foto`,
    // `revisado_por` y `revisado_en` NO están: solo los cambia el propio flujo del ticket
    protected $fillable = [
        'obra_id', 'user_id', 'categoria_id', 'tipo_pago', 'uuid_cliente', 'fecha_captura',
        'fecha_compra', 'proveedor', 'rfc_emisor', 'total',
        'qr_payload', 'uuid_sat', 'url_pdf', 'url_xml',
    ];

    // La ruta interna del archivo no sale en la API: el front recibe `url_foto` (enlace temporal firmado)
    protected $hidden = ['ruta_foto'];

    /**
     * `decimal:2` conserva el texto con 2 decimales (no float, que perdería centavos).
     * `date:Y-m-d`: `fecha_captura` y `fecha_compra` son un día del calendario ("2026-10-23"); con hora y zona
     * horaria, en el front podría mostrarse como el día anterior.
     */
    protected function casts(): array
    {
        return [
            'fecha_captura' => 'date:Y-m-d',
            'fecha_compra' => 'date:Y-m-d',
            'total' => 'decimal:2',
            'revisado_en' => 'datetime',
        ];
    }

    /**
     * Obra a la que se cargarán los gastos.
     */
    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class);
    }

    /**
     * Quién tomó la foto (será el `user_id` de los gastos: es quien hizo la compra).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Categoría elegida al capturar; se asigna solo a los conceptos nuevos.
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    /**
     * Administrador que lo validó o rechazó.
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    /**
     * Productos leídos, en el orden del ticket.
     */
    public function renglones(): HasMany
    {
        return $this->hasMany(TicketRenglon::class)->orderBy('orden');
    }

    /**
     * Gastos creados al validar (uno por renglón).
     */
    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
    }

    /**
     * ¿El admin todavía puede corregirlo, validarlo o rechazarlo?
     */
    public function esEditable(): bool
    {
        return in_array($this->estado, self::EDITABLES, true);
    }
}
