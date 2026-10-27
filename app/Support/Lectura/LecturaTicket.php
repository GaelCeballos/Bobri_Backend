<?php

namespace App\Support\Lectura;

/**
 * Resultado de leer la foto de un ticket con un {@see \App\Contracts\LectorTicket}, SIN validar.
 *
 * Igual que {@see RenglonLeido}, viene de un servicio externo: el Job {@see \App\Jobs\LeerTicket}
 * valida cada dato y descarta los que no tienen sentido antes de guardarlos.
 *
 * @package App\Support\Lectura
 */
final readonly class LecturaTicket
{
    /**
     * @param string|null        $fecha      Fecha de compra como texto "AAAA-MM-DD"; null si no se leyó.
     * @param string|null        $proveedor  Nombre del negocio.
     * @param string|null        $rfcEmisor  RFC del negocio.
     * @param string|null        $total      Total del ticket como texto ("3450.00").
     * @param string|null        $qrPayload  Texto del código QR de factura, si el ticket lo trae.
     * @param list<RenglonLeido> $renglones  Productos en el orden del ticket.
     */
    public function __construct(
        public ?string $fecha,
        public ?string $proveedor,
        public ?string $rfcEmisor,
        public ?string $total,
        public ?string $qrPayload,
        public array $renglones,
    ) {
    }
}
