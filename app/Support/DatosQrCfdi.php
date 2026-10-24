<?php

namespace App\Support;

/**
 * Datos de una factura (CFDI) leídos de su código QR, ya validados por {@see LectorQrCfdi}.
 *
 * Es de solo lectura (`readonly`): una vez leído, nadie puede cambiar un dato a medias.
 *
 * @package App\Support
 */
final readonly class DatosQrCfdi
{
    /**
     * @param string $uuid         Folio fiscal del CFDI, en minúsculas ("6128396f-c09b-…").
     * @param string $rfcEmisor    RFC del proveedor que emitió la factura, en mayúsculas.
     * @param string $rfcReceptor  RFC de quien recibió la factura (debería ser la constructora).
     * @param string $total        Total de la factura con 2 decimales, como texto ("1500.00").
     */
    public function __construct(
        public string $uuid,
        public string $rfcEmisor,
        public string $rfcReceptor,
        public string $total,
    ) {
    }
}
