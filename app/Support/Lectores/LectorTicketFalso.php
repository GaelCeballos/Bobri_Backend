<?php

namespace App\Support\Lectores;

use App\Contracts\LectorTicket;
use App\Support\Lectura\LecturaTicket;
use App\Support\Lectura\RenglonLeido;
use App\Support\ZonaHoraria;

/**
 * Lector de prueba: NO lee la foto, devuelve siempre el mismo ticket de ferretería.
 *
 * Sirve para desarrollar el front y probar el flujo completo (subir → leer → revisar →
 * validar) mientras se elige el lector real. Se activa con `LECTOR_TICKETS=falso`;
 * NUNCA debe usarse en producción.
 *
 * @package App\Support\Lectores
 */
final class LectorTicketFalso implements LectorTicket
{
    /**
     * Ignora la imagen y devuelve un ticket de ejemplo con fecha de hoy.
     */
    public function leer(string $imagen): LecturaTicket
    {
        // Hoy en México: el ticket se toma el mismo día de la compra
        $fecha = now(ZonaHoraria::POR_DEFECTO)->toDateString();

        return new LecturaTicket(
            fecha: $fecha,
            proveedor: 'Ferretería de prueba',
            rfcEmisor: 'FPR200101AB1',
            total: '1913.50',
            qrPayload: null,
            renglones: [
                new RenglonLeido(descripcion: 'CEMENTO GRIS 50KG', cantidad: '10', unidadMedida: 'bulto', monto: '1850.00'),
                new RenglonLeido(descripcion: 'CLAVO 2 1/2 PULG', cantidad: '1', unidadMedida: 'kg', monto: '38.50'),
                // Renglón sin cantidad: el admin decide si es un producto o lo borra
                new RenglonLeido(descripcion: 'DESCUENTO', cantidad: null, unidadMedida: null, monto: '25.00'),
            ],
        );
    }
}
