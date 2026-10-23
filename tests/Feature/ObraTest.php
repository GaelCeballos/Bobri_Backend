<?php

namespace Tests\Feature;

use App\Models\Gasto;
use App\Models\Obra;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas HTTP de /api/obras.
 *
 * Las obras las administra solo el Administrador; el Trabajador solo consulta las activas
 * para elegir dónde capturar un gasto. Las obras no se borran: al terminar se marcan
 * Finalizada.
 *
 * RefreshDatabase ejecuta las migraciones una vez y envuelve cada prueba en una
 * transacción que se deshace al terminar: cada prueba empieza con la base limpia.
 */
final class ObraTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sin estado la base pone Activa y la respuesta lo trae (no null), para que el front
     * la muestre en la lista sin volver a consultar; el importe llega como texto con 2 decimales.
     */
    public function test_el_administrador_crea_una_obra_activa_por_defecto(): void
    {
        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)
            ->postJson('/api/obras', ['nombre' => 'Cendi 1', 'cliente' => 'Gobierno de Colima', 'importe_contrato' => 1500000.50])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'Activa')
            ->assertJsonPath('data.importe_contrato', '1500000.50');
    }

    /**
     * El semáforo divide el saldo entre el contrato; un contrato de 0 lo rompería, y
     * un tercer decimal se redondearía en silencio.
     */
    public function test_el_importe_del_contrato_debe_ser_mayor_que_cero_con_maximo_dos_decimales(): void
    {
        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)
            ->postJson('/api/obras', ['nombre' => 'Cendi 1', 'cliente' => 'Gobierno de Colima', 'importe_contrato' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('importe_contrato');
        $this->comoUsuario($admin)
            ->postJson('/api/obras', ['nombre' => 'Cendi 1', 'cliente' => 'Gobierno de Colima', 'importe_contrato' => '1500.555'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('importe_contrato');
        $this->assertDatabaseCount('obras', 0);
    }

    /**
     * El Administrador ve también las finalizadas (para consultarlas o reactivarlas) y
     * filtra por estado; sin el filtro, una obra terminada desaparecería de su panel.
     */
    public function test_el_administrador_ve_todas_las_obras_y_filtra_por_estado(): void
    {
        Obra::factory()->create();
        $finalizada = Obra::factory()->finalizada()->create();
        $admin = User::factory()->admin()->create();

        $this->comoUsuario($admin)->getJson('/api/obras')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->comoUsuario($admin)->getJson('/api/obras?estado=Finalizada')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $finalizada->id);
    }

    /**
     * El importe es información financiera del Administrador; aunque el front lo ocultara
     * viajaría en la respuesta. Pedir ?estado=Finalizada no le muestra las finalizadas
     * (no puede capturar gastos en ellas).
     */
    public function test_el_trabajador_solo_ve_obras_activas_y_sin_importe_del_contrato(): void
    {
        $activa = Obra::factory()->create();
        Obra::factory()->finalizada()->create();
        $trabajador = User::factory()->create();

        $this->comoUsuario($trabajador)->getJson('/api/obras?estado=Finalizada')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activa->id)
            ->assertJsonMissingPath('data.0.importe_contrato');
    }

    /**
     * Finalizar una obra es la forma de cerrarla: el trabajador deja de verla y ya no puede
     * cargarle gastos por error. Si no desapareciera, seguiría sumando gastos al contrato.
     */
    public function test_al_finalizar_una_obra_desaparece_de_la_lista_del_trabajador(): void
    {
        $obra = Obra::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->comoUsuario($admin)->patchJson("/api/obras/{$obra->id}", ['estado' => 'Finalizada'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'Finalizada');

        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->getJson('/api/obras')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * En un PATCH los campos son opcionales, pero si vienen no pueden borrar el dato.
     */
    public function test_editar_no_puede_dejar_el_nombre_vacio(): void
    {
        $obra = Obra::factory()->create(['nombre' => 'Cendi 1']);
        $admin = User::factory()->admin()->create();

        $this->comoUsuario($admin)->patchJson("/api/obras/{$obra->id}", ['nombre' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nombre');

        $this->assertDatabaseHas('obras', ['id' => $obra->id, 'nombre' => 'Cendi 1']);
    }

    /**
     * Las obras y sus importes son del Administrador: el Trabajador recibe 403 y nada cambia.
     * El detalle también se le niega porque incluye el importe del contrato.
     */
    public function test_el_trabajador_no_puede_crear_editar_ni_ver_el_detalle(): void
    {
        $obra = Obra::factory()->create(['nombre' => 'Cendi 1']);
        $trabajador = User::factory()->create();

        $this->comoUsuario($trabajador)
            ->postJson('/api/obras', ['nombre' => 'Otra', 'cliente' => 'X', 'importe_contrato' => 10])
            ->assertForbidden();
        $this->comoUsuario($trabajador)->patchJson("/api/obras/{$obra->id}", ['nombre' => 'Cambiada'])
            ->assertForbidden();
        $this->comoUsuario($trabajador)->getJson("/api/obras/{$obra->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('obras', 1);
        $this->assertDatabaseHas('obras', ['id' => $obra->id, 'nombre' => 'Cendi 1']);
    }

    /**
     * No existe DELETE /obras: borrar una obra perdería el historial contable de sus gastos.
     * Al terminar se marca Finalizada.
     */
    public function test_las_obras_no_se_borran_por_la_api(): void
    {
        $obra = Obra::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->comoUsuario($admin)->deleteJson("/api/obras/{$obra->id}")
            ->assertStatus(405);

        $this->assertDatabaseHas('obras', ['id' => $obra->id]);
    }

    /**
     * La llave gastos.obra_id es restrictOnDelete; antes era en cascada y borrar una obra
     * borraba en silencio todos sus gastos.
     */
    public function test_una_obra_con_gastos_no_se_puede_borrar_ni_desde_codigo(): void
    {
        $obra = Obra::factory()->create();
        Gasto::factory()->create(['obra_id' => $obra->id]);

        $this->expectException(QueryException::class);
        $obra->delete();
    }
}
