<?php

namespace App\Support\Lectura;

/**
 * Un producto tal como lo leyó el lector del ticket, SIN validar.
 *
 * Los valores vienen de un servicio externo (OCR o IA) y pueden ser absurdos: el Job
 * {@see \App\Jobs\LeerTicket} los limpia antes de guardarlos.
 *
 * @package App\Support\Lectura
 */
final readonly class RenglonLeido
{
    /**
     * @param string      $descripcion   Texto del renglón tal cual ("LAM GALV C26 3.05").
     * @param string|null $cantidad      Cantidad como texto ("2", "1.5"); null si no se leyó.
     * @param string|null $unidadMedida  Unidad ("pza", "kg"); null si no se leyó.
     * @param string|null $monto         Importe del renglón como texto ("1234.50"); null si no se leyó.
     */
    public function __construct(
        public string $descripcion,
        public ?string $cantidad,
        public ?string $unidadMedida,
        public ?string $monto,
    ) {
    }
}
