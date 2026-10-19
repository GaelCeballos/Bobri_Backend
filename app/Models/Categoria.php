<?php

namespace App\Models;

use App\Models\Concerns\TieneNombreNormalizado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de gasto (Materiales, Mano de Obra, Equipo/Herramienta…).
 *
 * Es un catálogo que crece: cuando un usuario escribe una categoría que no
 * existía, se agrega. El trait {@see TieneNombreNormalizado} evita duplicados
 * escritos de distinta forma ("Herramienta menor" = "HERRAMIENTA  menor").
 * Agrupa conceptos para la gráfica "Gasto por categoría".
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $nombre Como lo escribió el usuario (se muestra en pantalla)
 * @property string $nombre_normalizado Huella única calculada por el trait
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Concepto> $conceptos
 */
class Categoria extends Model
{
    use TieneNombreNormalizado;

    /**
     * Tabla asociada. Laravel la deduciría bien, pero al ser un nombre en
     * español se declara explícita para que no haya dudas.
     *
     * @var string
     */
    protected $table = 'categorias';

    /**
     * Campos asignables desde una petición. `nombre_normalizado` no está aquí
     * a propósito: lo calcula siempre el trait y nunca debe venir del cliente.
     *
     * @var list<string>
     */
    protected $fillable = ['nombre'];

    /**
     * Conceptos que pertenecen a esta categoría.
     */
    public function conceptos(): HasMany
    {
        return $this->hasMany(Concepto::class);
    }
}
