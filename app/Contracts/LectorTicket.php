<?php

namespace App\Contracts;

use App\Support\Lectura\LecturaFallida;
use App\Support\Lectura\LecturaTicket;

/**
 * Lee los datos de la foto de un ticket (encabezado y productos).
 *
 * Es un contrato para poder cambiar de servicio sin tocar el resto del código: en desarrollo
 * y en las pruebas se usa {@see \App\Support\Lectores\LectorTicketFalso}; el lector real
 * (OCR o IA) se elegirá cuando haya tickets reales para probarlo. Cuál se usa lo decide
 * `LECTOR_TICKETS` en el .env (ver AppServiceProvider).
 *
 * @package App\Contracts
 */
interface LectorTicket
{
    /**
     * @param string $imagen Contenido binario de la foto ya reprocesada (WebP, sin EXIF).
     *
     * @throws LecturaFallida Si la foto no se puede leer (no es un ticket, está borrosa…).
     *                        Es definitivo: no se reintenta. Su mensaje lo ve el Administrador.
     * @throws \Throwable     Cualquier otro error (sin red, servicio caído) es temporal: la cola
     *                        reintenta la lectura.
     */
    public function leer(string $imagen): LecturaTicket;
}
