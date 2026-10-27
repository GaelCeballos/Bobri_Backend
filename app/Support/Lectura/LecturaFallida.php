<?php

namespace App\Support\Lectura;

use RuntimeException;

/**
 * La foto no se pudo leer y reintentar no lo arreglará (no es un ticket, está borrosa…).
 *
 * El mensaje se guarda en `tickets.error_lectura` y lo ve el Administrador: debe estar en
 * español y no incluir detalles internos del servicio.
 *
 * @package App\Support\Lectura
 */
final class LecturaFallida extends RuntimeException
{
}
