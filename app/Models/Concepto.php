<?php

namespace App\Models;

use App\Models\Concerns\TieneNombreNormalizado;
use App\Support\NormalizadorTexto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Concepto de gasto: lo que se compra o renta en la obra
 * ("Renta de rotomartillo", "MORTERO Cemex 50kg").
 *
 * Es un catálogo que crece con lo que escriben los usuarios. El trait
 * {@see TieneNombreNormalizado} impide duplicados escritos de distinta forma
 * ("mortero CEMEX 50 kilos" reutiliza "MORTERO Cemex 50kg"), para que las
 * gráficas sumen todo en un solo concepto.
 *
 * Pertenece a una {@see Categoria} y tiene muchos {@see Gasto}.
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $nombre Como lo escribió el usuario (se muestra en pantalla)
 * @property string $nombre_normalizado Huella única calculada por el trait
 * @property string $palabras_clave Firma para detectar posibles duplicados (no única)
 * @property int $categoria_id
 * @property string|null $unidad_medida Unidad sugerida para el formulario de gasto; null en pagos de mano de obra o servicios
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Categoria $categoria
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Gasto> $gastos
 */
class Concepto extends Model
{
    use TieneNombreNormalizado;

    /**
     * Campos asignables desde una petición. `nombre_normalizado` no está aquí
     * a propósito: lo calcula siempre el trait y nunca debe venir del cliente.
     *
     * @var list<string>
     */
    protected $fillable = ['nombre', 'categoria_id', 'unidad_medida'];

    /**
     * Antes de cada INSERT o UPDATE recalcula `palabras_clave` a partir del nombre,
     * igual que el trait hace con `nombre_normalizado`. Así la firma nunca queda
     * desactualizada cuando el administrador corrige un nombre, y nunca viene del cliente.
     */
    protected static function booted(): void
    {
        static::saving(function (Concepto $concepto): void {
            $concepto->palabras_clave = NormalizadorTexto::palabrasClave((string) $concepto->nombre);
        });
    }

    /**
     * Categoría a la que pertenece (sustituye al antiguo enum `categoria`).
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    /**
     * Gastos registrados con este concepto.
     */
    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
    }
}
