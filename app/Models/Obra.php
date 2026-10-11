<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class Obra
 *
 * @package App\Models
 * @property string $nombre
 * @property string $cliente
 * @property string $ubicacion
 * @property float $importe_contrato
 * @property string $estado
 */
class Obra extends Model
{   
    // Proteger los campos que se pueden asignar masivamente
    protected $fillable = ['nombre', 'cliente', 'ubicacion', 'importe_contrato', 'estado'];

    // Definir la relación con el modelo Gasto
    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
    }
}
