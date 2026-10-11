<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class Concepto
 *
 * @package App\Models
 * @property string $nombre
 * @property string $categoria
 * @property string $unidad_medida
 */
class Concepto extends Model
{
    // Proteger los campos que se pueden asignar masivamente
    protected $fillable = ['nombre', 'categoria', 'unidad_medida'];

    // Definir la relación con el modelo Gasto
    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
    }
}
