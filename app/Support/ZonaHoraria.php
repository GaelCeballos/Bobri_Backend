<?php

namespace App\Support;

use DateTimeZone;
use Illuminate\Http\Request;

/**
 * Zona horaria del dispositivo que hace la petición.
 *
 * La base de datos guarda los momentos (`created_at`, …) en UTC y el front los convierte
 * a la zona del dispositivo al mostrarlos. Para las reglas que dependen de "hoy" (p. ej.
 * "la fecha del gasto no puede ser futura") se usa el hoy DEL DISPOSITIVO: a las 7 p. m.
 * en Colima, en UTC ya es mañana, y sin esto se aceptaría la fecha del día siguiente.
 *
 * El front manda la zona en la cabecera `X-Zona-Horaria` (la obtiene del navegador con
 * `Intl.DateTimeFormat().resolvedOptions().timeZone`, sin pedir permiso). Si no llega o no
 * es una zona válida, se usa la de la Ciudad de México.
 *
 * @package App\Support
 */
final class ZonaHoraria
{
    public const CABECERA = 'X-Zona-Horaria';

    public const POR_DEFECTO = 'America/Mexico_City';

    /**
     * Zona del dispositivo, o la de CDMX si la cabecera falta o no es válida.
     *
     * Solo se aceptan nombres de la lista oficial de PHP ("America/Mexico_City"), nunca
     * desplazamientos sueltos: así el valor siempre es seguro para pasarlo a Carbon.
     */
    public static function delDispositivo(Request $request): string
    {
        $zona = trim((string) $request->header(self::CABECERA, ''));

        return in_array($zona, DateTimeZone::listIdentifiers(), true) ? $zona : self::POR_DEFECTO;
    }

    /**
     * Fecha de hoy en la zona del dispositivo ("2026-10-23").
     */
    public static function hoy(Request $request): string
    {
        return now(self::delDispositivo($request))->toDateString();
    }
}
