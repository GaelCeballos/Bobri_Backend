<?php

namespace Tests\Feature;

use App\Models\Concepto;
use App\Models\Gasto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas HTTP para la fusión de conceptos y el reporte de posibles duplicados.
 *
 * Prueba los endpoints /api/conceptos/{id}/fusionar y /api/conceptos/posibles-duplicados
 * que son herramientas del Administrador para limpiar duplicados. El backend mueve los gastos
 * del duplicado al concepto correcto y borra el duplicado en la fusión, mientras que el
 * reporte agrupa conceptos con las mismas palabras clave.
 *
 * RefreshDatabase ejecuta las migraciones una vez y envuelve cada prueba en una
 * transacción que se deshace al terminar: cada prueba empieza sin conceptos, gastos ni
 * usuarios.
 *
 * Las peticiones se autentican con {@see TestCase::comoUsuario()}, que pasa por el
 * middleware real (sesión única, usuario activo y rol).
 */
final class FusionarConceptoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Los gastos conservan monto, obra y trabajador; solo cambian de concepto,
     * así las gráficas suman todo en uno.
     */
    public function test_fusionar_mueve_los_gastos_al_destino_y_borra_el_duplicado(): void
    {
        $duplicado = Concepto::factory()->create(['nombre' => 'Compra de pala']);
        $destino = Concepto::factory()->create(['nombre' => 'Pala']);
        Gasto::factory()->count(3)->create(['concepto_id' => $duplicado->id]);
        Gasto::factory()->create(['concepto_id' => $destino->id]);

        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)->postJson("/api/conceptos/{$duplicado->id}/fusionar", ['destino_id' => $destino->id])
            ->assertOk()
            ->assertJsonPath('data.gastos_movidos', 3)
            ->assertJsonPath('data.concepto.id', $destino->id)
            ->assertJsonPath('data.advertencia', null);

        $this->assertDatabaseMissing('conceptos', ['id' => $duplicado->id]);
        $this->assertSame(4, Gasto::where('concepto_id', $destino->id)->count());
    }

    /**
     * Un gasto borrado (soft delete) sigue en la tabla apuntando a su concepto. Si la
     * fusión no lo moviera, la llave restrictOnDelete impediría borrar el duplicado y el
     * admin recibiría un 500. Se mueve también, por si el admin lo consulta después.
     */
    public function test_fusionar_tambien_mueve_los_gastos_borrados(): void
    {
        $duplicado = Concepto::factory()->create(['nombre' => 'Compra de pala']);
        $destino = Concepto::factory()->create(['nombre' => 'Pala']);
        $borrado = Gasto::factory()->create(['concepto_id' => $duplicado->id]);
        $borrado->delete();

        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)->postJson("/api/conceptos/{$duplicado->id}/fusionar", ['destino_id' => $destino->id])
            ->assertOk();

        $this->assertDatabaseMissing('conceptos', ['id' => $duplicado->id]);
        $this->assertSoftDeleted('gastos', ['id' => $borrado->id, 'concepto_id' => $destino->id]);
    }

    /**
     * La fusión se hace igual, pero si las unidades sugeridas difieren el admin recibe
     * un aviso: las cantidades movidas podrían dejar de ser comparables.
     */
    public function test_fusionar_conceptos_con_unidades_distintas_devuelve_una_advertencia(): void
    {
        $duplicado = Concepto::factory()->create(['nombre' => 'Cemento gris 50 kg', 'unidad_medida' => 'saco']);
        $destino = Concepto::factory()->create(['nombre' => 'Cemento de 50 kg', 'unidad_medida' => 'tonelada']);

        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)->postJson("/api/conceptos/{$duplicado->id}/fusionar", ['destino_id' => $destino->id])
            ->assertOk()
            ->assertJsonPath('data.advertencia', 'Las unidades eran distintas («saco» y «tonelada»); revisa las cantidades de los gastos movidos.');
    }

    /**
     * Un concepto sin unidad (mano de obra o servicio) aparece como «sin unidad» en el
     * aviso, en lugar de un «» vacío que el admin no entendería.
     */
    public function test_la_advertencia_muestra_sin_unidad_cuando_un_concepto_no_tiene(): void
    {
        $duplicado = Concepto::factory()->sinUnidad()->create(['nombre' => 'Pago a fontanero Manuel']);
        $destino = Concepto::factory()->create(['nombre' => 'Fontanero Manuel', 'unidad_medida' => 'pieza']);

        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)->postJson("/api/conceptos/{$duplicado->id}/fusionar", ['destino_id' => $destino->id])
            ->assertOk()
            ->assertJsonPath('data.advertencia', 'Las unidades eran distintas («sin unidad» y «pieza»); revisa las cantidades de los gastos movidos.');
    }

    /**
     * Fusionar un concepto consigo mismo lo borraría junto con sus gastos movidos a él
     * mismo: se rechaza con 422 y el concepto sigue existiendo.
     */
    public function test_no_se_puede_fusionar_un_concepto_consigo_mismo(): void
    {
        $concepto = Concepto::factory()->create();

        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)->postJson("/api/conceptos/{$concepto->id}/fusionar", ['destino_id' => $concepto->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destino_id');

        $this->assertDatabaseHas('conceptos', ['id' => $concepto->id]);
    }

    /**
     * Con un destino inexistente no se mueve ni se borra nada: 422 con el error en
     * `destino_id` para que el panel lo muestre junto al selector.
     */
    public function test_un_destino_que_no_existe_responde_422(): void
    {
        $concepto = Concepto::factory()->create();

        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)->postJson("/api/conceptos/{$concepto->id}/fusionar", ['destino_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destino_id');
    }

    /**
     * Conservar el más usado mueve menos gastos; "Pala" no aparece porque no tiene parecidos.
     */
    public function test_el_reporte_agrupa_los_posibles_duplicados_y_sugiere_el_mas_usado(): void
    {
        $poco = Concepto::factory()->create(['nombre' => 'Saco de cemento 50 kg']);
        $mucho = Concepto::factory()->create(['nombre' => 'Cemento de 50 kg']);
        Concepto::factory()->create(['nombre' => 'Pala']);
        Gasto::factory()->create(['concepto_id' => $poco->id]);
        Gasto::factory()->count(3)->create(['concepto_id' => $mucho->id]);

        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)->getJson('/api/conceptos/posibles-duplicados')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.palabras_clave', '50kg cemento')
            ->assertJsonPath('data.0.sugerido_destino_id', $mucho->id)
            ->assertJsonCount(2, 'data.0.conceptos')
            ->assertJsonPath('data.0.conceptos.0.gastos_count', 3);
    }

    /**
     * Fusionar borra un concepto y cambia todas las gráficas: es solo del Administrador.
     * El Trabajador recibe 403 y el duplicado sigue existiendo.
     */
    public function test_un_trabajador_no_puede_fusionar_conceptos(): void
    {
        $duplicado = Concepto::factory()->create();
        $destino = Concepto::factory()->create();

        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson("/api/conceptos/{$duplicado->id}/fusionar", ['destino_id' => $destino->id])
            ->assertForbidden();

        $this->assertDatabaseHas('conceptos', ['id' => $duplicado->id]);
    }

    /**
     * El reporte es una herramienta de limpieza del panel de administración: el
     * Trabajador recibe 403.
     */
    public function test_un_trabajador_no_puede_ver_el_reporte_de_posibles_duplicados(): void
    {
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->getJson('/api/conceptos/posibles-duplicados')
            ->assertForbidden();
    }
}
