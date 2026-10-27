<?php

namespace Tests\Feature;

use App\Models\Concepto;
use App\Models\Obra;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pruebas HTTP del registro de gastos (POST /api/gastos).
 *
 * Ambos roles registran gastos desde el teléfono, a menudo sin señal: estas pruebas fijan las
 * reglas del formulario (Concepto · Cantidad · Unidad · Monto), que un reenvío no duplique el
 * gasto y que una factura no se registre dos veces.
 *
 * RefreshDatabase ejecuta las migraciones una vez y envuelve cada prueba en una
 * transacción que se deshace al terminar: cada prueba empieza con la base limpia.
 */
final class GastoRegistroTest extends TestCase
{
    use RefreshDatabase;

    /** QR de una factura del SAT (CFDI 4.0) por $3,200.00 */
    private const QR = 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx'
        .'?id=6128396f-c09b-4ec6-8699-43c5f7e3b230&re=FER850101AB1&rr=BCO100512XY9&tt=3200';

    /**
     * Inicia sesión con un trabajador nuevo (registrar gastos es de ambos roles).
     */
    private function comoTrabajador(): static
    {
        return $this->comoUsuario(User::factory()->create());
    }

    /**
     * Cuerpo válido de POST /api/gastos (un servicio: sin cantidad ni unidad).
     * Cada llamada crea su obra y su concepto y genera un uuid_cliente nuevo, como el teléfono.
     *
     * @param array<string, mixed> $cambios Campos que la prueba quiere cambiar o agregar.
     * @return array<string, mixed>
     */
    private function datosGasto(array $cambios = []): array
    {
        return [
            'uuid_cliente' => (string) Str::uuid(),
            'obra_id' => Obra::factory()->create()->id,
            'concepto_id' => Concepto::factory()->create()->id,
            'monto_total' => 1500,
            'tipo_pago' => 'Efectivo',
            'fecha_gasto' => now('America/Mexico_City')->toDateString(),
            ...$cambios,
        ];
    }

    /**
     * Quién registró se toma de la sesión, nunca del cuerpo de la petición; los decimales
     * llegan como texto con 2 decimales.
     */
    public function test_una_compra_se_registra_con_cantidad_y_unidad(): void
    {
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)
            ->postJson('/api/gastos', $this->datosGasto([
                'cantidad' => 2,
                'unidad_medida' => 'saco',
                'monto_total' => 3200,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.cantidad', '2.00')
            ->assertJsonPath('data.unidad_medida', 'saco')
            ->assertJsonPath('data.monto_total', '3200.00')
            ->assertJsonPath('data.user.id', $trabajador->id);
    }

    /**
     * Mano de obra y servicios ("Pago a electricista") no tienen cantidad ni unidad: se
     * registran solo con el monto.
     */
    public function test_un_servicio_se_registra_sin_cantidad_ni_unidad(): void
    {
        $this->comoTrabajador()
            ->postJson('/api/gastos', $this->datosGasto())
            ->assertCreated()
            ->assertJsonPath('data.cantidad', null)
            ->assertJsonPath('data.unidad_medida', null);
    }

    /**
     * Una cantidad sin unidad no se puede sumar en "Lo más comprado".
     */
    public function test_cantidad_y_unidad_van_en_pareja(): void
    {
        $this->comoTrabajador()
            ->postJson('/api/gastos', $this->datosGasto(['cantidad' => 2]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unidad_medida');
        $this->assertDatabaseCount('gastos', 0);
    }

    /**
     * Finalizar una obra la cierra: si se aceptaran gastos nuevos, seguirían sumando a un
     * contrato ya terminado.
     */
    public function test_no_se_registran_gastos_en_una_obra_finalizada(): void
    {
        $this->comoTrabajador()
            ->postJson('/api/gastos', $this->datosGasto(['obra_id' => Obra::factory()->finalizada()->create()->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('obra_id');
    }

    /**
     * A las 7:30 p. m. en Colima ya es el día 24 en UTC; la regla usa el hoy del dispositivo
     * (cabecera X-Zona-Horaria). Si usara el de UTC, aceptaría la fecha de mañana.
     */
    public function test_la_fecha_no_puede_ser_futura_en_el_dia_del_dispositivo(): void
    {
        $this->travelTo(now()->parse('2026-10-24 01:30:00', 'UTC'));
        $peticion = $this->comoTrabajador()->withHeader('X-Zona-Horaria', 'America/Mexico_City');

        $peticion->postJson('/api/gastos', $this->datosGasto(['fecha_gasto' => '2026-10-24']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fecha_gasto');

        $peticion->postJson('/api/gastos', $this->datosGasto(['fecha_gasto' => '2026-10-23']))
            ->assertCreated();
    }

    /**
     * Sin señal, el teléfono reintenta el envío aunque el primero haya llegado; el uuid_cliente
     * lo identifica y se devuelve el gasto ya creado en lugar de duplicarlo.
     */
    public function test_reenviar_la_misma_captura_no_duplica_el_gasto(): void
    {
        $trabajador = User::factory()->create();
        $datos = $this->datosGasto();
        $id = $this->comoUsuario($trabajador)
            ->postJson('/api/gastos', $datos)
            ->assertCreated()
            ->json('data.id');

        $this->comoUsuario($trabajador)
            ->postJson('/api/gastos', $datos)
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseCount('gastos', 1);
    }

    /**
     * Si se devolviera el gasto existente, cualquiera que conozca un uuid_cliente vería el
     * gasto de otra persona.
     */
    public function test_el_uuid_cliente_de_otro_usuario_no_revela_su_gasto(): void
    {
        $datos = $this->datosGasto();
        $trabajadorA = User::factory()->create();

        $this->comoUsuario($trabajadorA)
            ->postJson('/api/gastos', $datos)
            ->assertCreated();

        $trabajadorB = User::factory()->create();
        $this->comoUsuario($trabajadorB)
            ->postJson('/api/gastos', $datos)
            ->assertConflict()
            ->assertJsonPath('code', 'UUID_CLIENTE_EN_USO')
            ->assertJsonMissingPath('data');

        $this->assertDatabaseCount('gastos', 1);
    }

    /**
     * El RFC del proveedor alimenta "Proveedores con más pagos". Un monto distinto al de la
     * factura se registra (puede ser un pago parcial), pero se avisa.
     */
    public function test_el_qr_guarda_la_factura_y_avisa_si_el_monto_no_coincide(): void
    {
        $this->comoTrabajador()
            ->postJson('/api/gastos', $this->datosGasto(['qr_payload' => self::QR, 'monto_total' => 3100]))
            ->assertCreated()
            ->assertJsonPath('data.uuid_sat', '6128396f-c09b-4ec6-8699-43c5f7e3b230')
            ->assertJsonPath('data.rfc_emisor', 'FER850101AB1')
            ->assertJsonPath(
                'advertencia',
                'El monto capturado ($3100.00) no coincide con el total de la factura ($3200.00).'
            );
    }

    /**
     * Registrar dos veces la misma factura haría que la compra contara doble en el gasto de la obra.
     */
    public function test_la_misma_factura_no_se_registra_dos_veces(): void
    {
        $this->comoTrabajador()
            ->postJson('/api/gastos', $this->datosGasto(['qr_payload' => self::QR, 'monto_total' => 3200]))
            ->assertCreated();

        $this->comoTrabajador()
            ->postJson('/api/gastos', $this->datosGasto(['qr_payload' => self::QR, 'monto_total' => 3200]))
            ->assertConflict()
            ->assertJsonPath('code', 'FACTURA_DUPLICADA');

        $this->assertDatabaseCount('gastos', 1);
    }

    /**
     * Un QR de otro sitio (publicidad, un enlace malicioso) no se guarda como factura; el
     * usuario ve el motivo junto al campo.
     */
    public function test_un_qr_que_no_es_del_sat_responde_422(): void
    {
        $this->comoTrabajador()
            ->postJson('/api/gastos', $this->datosGasto(['qr_payload' => 'https://sat.gob.mx.evil.com/?id=1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qr_payload' => 'El código QR no corresponde a una factura del SAT.']);
    }
}
