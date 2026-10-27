<?php

namespace Tests\Feature;

use App\Models\Concepto;
use App\Models\Gasto;
use App\Models\Obra;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pruebas HTTP de la consulta, corrección y borrado de gastos.
 *
 * La lista completa es del Administrador; cada trabajador ve sus gastos en /gastos/mios.
 * Corregir y borrar lo pueden el Administrador o quien registró el gasto (GastoPolicy).
 * Borrar es soft delete: el gasto deja de contar, pero el admin ve quién lo borró.
 *
 * RefreshDatabase ejecuta las migraciones una vez y envuelve cada prueba en una
 * transacción que se deshace al terminar: cada prueba empieza con la base limpia.
 */
final class GastoGestionTest extends TestCase
{
    use RefreshDatabase;

    /** Folio fiscal (UUID) de la factura del QR de abajo */
    private const UUID_SAT = '6128396f-c09b-4ec6-8699-43c5f7e3b230';

    /** QR de una factura del SAT (CFDI 4.0) por $3,200.00 */
    private const QR = 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx'
        .'?id='.self::UUID_SAT.'&re=FER850101AB1&rr=BCO100512XY9&tt=3200';

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
     * La lista completa expone los gastos de todas las obras y trabajadores.
     */
    public function test_el_trabajador_no_ve_la_lista_completa(): void
    {
        $trabajador = User::factory()->create();

        $this->comoUsuario($trabajador)
            ->getJson('/api/gastos')
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo el administrador puede consultar todos los gastos.');
    }

    /**
     * Es el historial del teléfono: si trajera gastos de otros, el trabajador los vería como
     * suyos (y conocería montos de otras obras).
     */
    public function test_mios_solo_trae_los_gastos_propios(): void
    {
        $trabajador = User::factory()->create();
        Gasto::factory()->count(2)->create(['user_id' => $trabajador->id]);
        Gasto::factory()->create();

        $this->comoUsuario($trabajador)
            ->getJson('/api/gastos/mios')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    /**
     * La suma es de todo lo filtrado (no solo de la página) y es el total que muestra la
     * pantalla de auditoría.
     */
    public function test_el_admin_filtra_por_obra_y_fechas_y_recibe_la_suma(): void
    {
        $obra = Obra::factory()->create();
        Gasto::factory()->create(['obra_id' => $obra->id, 'monto_total' => 1000, 'fecha_gasto' => '2026-10-10']);
        Gasto::factory()->create(['obra_id' => $obra->id, 'monto_total' => 500, 'fecha_gasto' => '2026-10-20']);
        Gasto::factory()->create(['monto_total' => 9999, 'fecha_gasto' => '2026-10-10']);
        $admin = User::factory()->admin()->create();

        $this->comoUsuario($admin)
            ->getJson("/api/gastos?obra_id={$obra->id}&desde=2026-10-01&hasta=2026-10-15")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.suma_monto', '1000.00');
    }

    /**
     * Busca como las sugerencias del front (cada palabra, sin acentos ni mayúsculas). Un "%"
     * escrito por el usuario no debe actuar como comodín y devolver todos los gastos.
     */
    public function test_la_busqueda_encuentra_el_concepto_por_cada_palabra_y_escapa_comodines(): void
    {
        $concepto = Concepto::factory()->create(['nombre' => 'Cemento de 50 kg']);
        Gasto::factory()->create(['concepto_id' => $concepto->id]);
        $varilla = Concepto::factory()->create(['nombre' => 'Varilla 3/8']);
        Gasto::factory()->create(['concepto_id' => $varilla->id]);
        $admin = User::factory()->admin()->create();

        $this->comoUsuario($admin)
            ->getJson('/api/gastos?buscar='.urlencode('CEMENTO 50kg'))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.concepto.id', $concepto->id);

        $this->comoUsuario($admin)
            ->getJson('/api/gastos?buscar='.urlencode('%'))
            ->assertJsonCount(0, 'data');
    }

    /**
     * Decisión del dueño (opción A): al corregir se guarda solo quién lo corrigió y cuándo,
     * sin historial de valores anteriores.
     */
    public function test_el_dueno_corrige_su_gasto_y_queda_quien_lo_corrigio(): void
    {
        $trabajador = User::factory()->create();
        $gasto = Gasto::factory()->create(['user_id' => $trabajador->id]);

        $this->comoUsuario($trabajador)
            ->patchJson("/api/gastos/{$gasto->id}", ['monto_total' => 2300])
            ->assertOk()
            ->assertJsonPath('data.monto_total', '2300.00')
            ->assertJsonPath('data.actualizado_por.id', $trabajador->id);
    }

    /**
     * Un trabajador no toca los gastos de otro aunque sean de la misma obra: podría bajar
     * montos o borrar compras ajenas sin que nadie lo note.
     */
    public function test_otro_trabajador_no_puede_corregir_ni_borrar(): void
    {
        $gasto = Gasto::factory()->create(['monto_total' => 1000]);
        $otro = User::factory()->create();

        $this->comoUsuario($otro)
            ->patchJson("/api/gastos/{$gasto->id}", ['monto_total' => 1])
            ->assertForbidden();

        $this->comoUsuario($otro)
            ->deleteJson("/api/gastos/{$gasto->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('gastos', [
            'id' => $gasto->id,
            'monto_total' => 1000,
            'deleted_at' => null,
        ]);
    }

    /**
     * El PATCH no trae la unidad, pero el gasto ya la tiene: quedaría una unidad sin cantidad
     * y la restricción de la base de datos respondería 500. La validación lo detecta antes.
     */
    public function test_quitar_solo_la_cantidad_responde_422(): void
    {
        $gasto = Gasto::factory()->create(['cantidad' => 2, 'unidad_medida' => 'saco']);
        $admin = User::factory()->admin()->create();

        $this->comoUsuario($admin)
            ->patchJson("/api/gastos/{$gasto->id}", ['cantidad' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cantidad');

        $this->assertDatabaseHas('gastos', ['id' => $gasto->id, 'unidad_medida' => 'saco']);
    }

    /**
     * Decisión del dueño: sin bitácora, el soft delete es la única forma de saber que existió
     * un gasto borrado y quién lo borró.
     */
    public function test_borrar_es_soft_delete_y_el_admin_ve_quien_lo_borro(): void
    {
        $trabajador = User::factory()->create();
        $gasto = Gasto::factory()->create(['user_id' => $trabajador->id]);

        $this->comoUsuario($trabajador)
            ->deleteJson("/api/gastos/{$gasto->id}")
            ->assertOk();

        $this->assertSoftDeleted('gastos', ['id' => $gasto->id, 'eliminado_por' => $trabajador->id]);

        $admin = User::factory()->admin()->create();

        $this->comoUsuario($admin)
            ->getJson('/api/gastos')
            ->assertJsonCount(0, 'data');

        $this->comoUsuario($admin)
            ->getJson('/api/gastos?eliminados=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.eliminado_por.id', $trabajador->id);
    }

    /**
     * Un gasto borrado ya no cuenta: si se pudiera corregir, cambiarían cifras que nadie ve.
     */
    public function test_un_gasto_borrado_ya_no_se_puede_corregir(): void
    {
        $gasto = Gasto::factory()->create();
        $gasto->delete();

        $admin = User::factory()->admin()->create();

        $this->comoUsuario($admin)
            ->patchJson("/api/gastos/{$gasto->id}", ['monto_total' => 1])
            ->assertNotFound();
    }

    /**
     * Si el trabajador borra un gasto mal capturado, debe poder volver a capturar esa factura
     * (el índice único de uuid_sat ignora los gastos borrados).
     */
    public function test_borrar_un_gasto_libera_su_factura(): void
    {
        $trabajador = User::factory()->create();
        $gasto = Gasto::factory()->create(['user_id' => $trabajador->id, 'uuid_sat' => self::UUID_SAT]);

        $this->comoUsuario($trabajador)
            ->deleteJson("/api/gastos/{$gasto->id}")
            ->assertOk();

        $this->comoUsuario($trabajador)
            ->postJson('/api/gastos', $this->datosGasto(['qr_payload' => self::QR, 'monto_total' => 3200]))
            ->assertCreated();
    }
}
