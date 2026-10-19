<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use App\Support\NormalizadorTexto;
use InvalidArgumentException;

/**
 * Trait TieneNombreNormalizado
 *
 * Este trait se utiliza en los modelos de catálogos que alimentan los usuarios,
 * como Categoria y Concepto. Cada tabla tiene columnas 'nombre' (como lo escribió
 * el usuario) y 'nombre_normalizado' (huella única calculada con App\Support\NormalizadorTexto::normalizar).
 * El trait garantiza que la huella siempre se calcule en el backend y nunca venga
 * del cliente.
 */
trait TieneNombreNormalizado
{
    /**
     * Laravel ejecuta automáticamente los métodos `boot<NombreDelTrait>` al
     * iniciar el modelo que usa el trait.
     *
     * Registra un callback en el evento `saving`, que ocurre antes de cada
     * INSERT y de cada UPDATE. Así la huella se recalcula también cuando el
     * administrador corrige el nombre de un registro existente.
     *
     * @throws InvalidArgumentException si el nombre no tiene letras ni números (p. ej. "---").
     */
    public static function bootTieneNombreNormalizado(): void
    {
        static::saving(function (Model $modelo): void {
            // Calcular la huella normalizada del nombre
            $huella = NormalizadorTexto::normalizar((string) $modelo->nombre);

            // Si la huella está vacía, lanzar una excepción
            if ($huella === '') {
                throw new InvalidArgumentException('El nombre debe contener letras o números.');
            }

            // Asignar la huella normalizada al modelo
            $modelo->nombre_normalizado = $huella;
        });
    }

    /**
     * Devuelve el registro cuya huella coincide con la de `$nombre`, o lo crea.
     *
     * Ejemplo: si existe "MORTERO Cemex 50kg", buscarOCrear('mortero CEMEX 50 kilos')
     * devuelve ese mismo registro en lugar de crear un duplicado.
     *
     * Seguro ante peticiones simultáneas: `createOrFirst()` intenta primero el
     * INSERT y, si el índice único lo rechaza porque otra persona lo acaba de
     * crear, hace un SELECT y devuelve el existente. (Buscar primero y crear
     * después dejaría una ventana en la que dos peticiones crean el mismo.)
     *
     * Si el registro ya existía NO se modifica: se conservan su nombre, su
     * categoría y su unidad originales, aunque `$atributos` traiga otros valores.
     *
     * Usa `$registro->wasRecentlyCreated` para saber qué pasó: `true` = se creó
     * (responder 201 Created), `false` = ya existía (responder 200 OK).
     *
     * @param  string  $nombre  Nombre tal como lo escribió el usuario.
     * @param  array<string, mixed>  $atributos  Columnas extra solo para el caso de creación (p. ej. categoria_id).
     * @return static El registro encontrado o recién creado.
     *
     * @throws InvalidArgumentException si el nombre no tiene letras ni números.
     */
    public static function buscarOCrear(string $nombre, array $atributos = []): static
    {
        $huella = NormalizadorTexto::normalizar($nombre);

        // 1er arreglo: con qué se busca · 2º arreglo: qué más se guarda al crear
        return static::query()->createOrFirst(
            ['nombre_normalizado' => $huella],
            ['nombre' => $nombre, ...$atributos]
        );
    }
}
