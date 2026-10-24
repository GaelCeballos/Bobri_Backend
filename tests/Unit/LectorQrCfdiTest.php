<?php

namespace Tests\Unit;

use App\Support\LectorQrCfdi;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas unitarias de App\Support\LectorQrCfdi.
 *
 * El texto del QR lo manda el teléfono y puede venir de cualquier código (o de una petición
 * armada a mano): estas pruebas fijan qué se acepta como factura del SAT y qué se rechaza
 * antes de llegar a la base de datos.
 *
 * Extiende el TestCase de PHPUnit (no el de Laravel) porque no necesita base de
 * datos ni arrancar el framework: así estas pruebas corren en milisegundos.
 */
final class LectorQrCfdiTest extends TestCase
{
    /** Folio fiscal de ejemplo (formato real de un CFDI) */
    private const UUID = '6128396f-c09b-4ec6-8699-43c5f7e3b230';
    /** Dirección que imprime el SAT en los QR de CFDI 3.3 y 4.0 */
    private const URL_SAT = 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx';

    /**
     * Acepta los dos formatos que imprime el SAT (3.2 y 3.3/4.0). El RFC se guarda siempre en
     * mayúsculas: si no, "pegj800101ab1" y "PEGJ800101AB1" contarían como dos proveedores en
     * "Proveedores con más pagos". El total queda con 2 decimales para compararlo con el monto
     * capturado.
     *
     * @param string $qr        Código QR del CFDI.
     * @param string $rfcEmisor RFC del emisor de la factura.
     * @param string $total     Total de la factura.
     */
    #[DataProvider('qrValidos')]
    public function test_lee_los_formatos_que_imprime_el_sat(string $qr, string $rfcEmisor, string $total): void
    {
        $datos = LectorQrCfdi::leer($qr);
        $this->assertSame(self::UUID, $datos->uuid);
        $this->assertSame($rfcEmisor, $datos->rfcEmisor);
        $this->assertSame('BCO100512XY9', $datos->rfcReceptor);
        $this->assertSame($total, $datos->total);
    }

    /**
     * Casos de {@see test_lee_los_formatos_que_imprime_el_sat()}.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function qrValidos(): array
    {
        return [
            'CFDI 4.0 con UUID en mayusculas' => [self::URL_SAT.'?id=6128396F-C09B-4EC6-8699-43C5F7E3B230&re=FER850101AB1&rr=BCO100512XY9&tt=3712.000000&fe=Ab12Cd==', 'FER850101AB1', '3712.00'],
            'CFDI 3.2 solo parametros y total con ceros' => ['?re=FER850101AB1&rr=BCO100512XY9&tt=0000001500.000000&id='.self::UUID, 'FER850101AB1', '1500.00'],
            'persona fisica con RFC de 13 en minusculas' => [self::URL_SAT.'?id='.self::UUID.'&re=pegj800101ab1&rr=BCO100512XY9&tt=850.5', 'PEGJ800101AB1', '850.50'],
            'lector que entrega &amp;' => [self::URL_SAT.'?id='.self::UUID.'&amp;re=FER850101AB1&amp;rr=BCO100512XY9&amp;tt=10', 'FER850101AB1', '10.00'],
        ];
    }

    /**
     * Cada rechazo llega al usuario como error 422 con este mensaje. Sin esta validación se
     * guardaría como factura cualquier QR (publicidad, un enlace malicioso) o datos basura.
     *
     * @param string $qr      Código QR del CFDI.
     * @param string $mensaje Mensaje de error esperado.
     */
    #[DataProvider('qrInvalidos')]
    public function test_rechaza_lo_que_no_es_una_factura_del_sat(string $qr, string $mensaje): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($mensaje);
        LectorQrCfdi::leer($qr);
    }

    /**
     * Casos de {@see test_rechaza_lo_que_no_es_una_factura_del_sat()}.
     *
     * @return array<string, array{string, string}>
     */
    public static function qrInvalidos(): array
    {
        return [
            'otro dominio que imita al SAT' => ['https://sat.gob.mx.evil.com/default.aspx?id='.self::UUID.'&re=FER850101AB1&rr=BCO100512XY9&tt=10', 'El código QR no corresponde a una factura del SAT.'],
            'esquema javascript' => ['javascript:alert(1)', 'El código QR no corresponde a una factura del SAT.'],
            'texto cualquiera' => ['Hola mundo', 'El código QR no corresponde a una factura del SAT.'],
            'texto vacio' => ['   ', 'El código QR no corresponde a una factura del SAT.'],
            'texto enorme' => [str_repeat('a', 2000), 'El código QR no corresponde a una factura del SAT.'],
            'UUID incompleto' => [self::URL_SAT.'?id=123&re=FER850101AB1&rr=BCO100512XY9&tt=10', 'El código QR no trae un folio fiscal (UUID) válido.'],
            'UUID enviado como arreglo' => [self::URL_SAT.'?id[]='.self::UUID.'&re=FER850101AB1&rr=BCO100512XY9&tt=10', 'El código QR no trae un folio fiscal (UUID) válido.'],
            'RFC emisor invalido' => [self::URL_SAT.'?id='.self::UUID.'&re=XX&rr=BCO100512XY9&tt=10', 'El código QR no trae un RFC válido.'],
            'sin RFC receptor' => [self::URL_SAT.'?id='.self::UUID.'&re=FER850101AB1&tt=10', 'El código QR no trae un RFC válido.'],
            'total negativo' => [self::URL_SAT.'?id='.self::UUID.'&re=FER850101AB1&rr=BCO100512XY9&tt=-10', 'El código QR no trae un total válido.'],
            'total con letras' => [self::URL_SAT.'?id='.self::UUID.'&re=FER850101AB1&rr=BCO100512XY9&tt=10abc', 'El código QR no trae un total válido.'],
        ];
    }
}
