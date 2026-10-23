<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Concepto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas HTTP del catálogo de conceptos de gasto.
 *
 * Prueba los endpoints /api/conceptos tal como los usa el front: un catálogo que
 * crece con lo que escriben los usuarios, sin duplicados. El backend los evita en capas:
 *  - Huella única: el mismo nombre escrito distinto ("CEMENTO de 50 kilos") → 200 con el existente.
 *  - Palabras clave: un nombre parecido ("Saco de cemento 50 kg") → 409 POSIBLE_DUPLICADO
 *    hasta que el usuario confirme que es distinto.
 *
 * RefreshDatabase ejecuta las migraciones una vez y envuelve cada prueba en una
 * transacción que se deshace al terminar: cada prueba empieza sin conceptos y con
 * las 5 categorías que siembra la migración (Materiales, Mano de Obra, …).
 *
 * Las peticiones se autentican con {@see TestCase::comoUsuario()}, que pasa por el
 * middleware real (sesión única, usuario activo y rol).
 */
final class ConceptoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El front descarga el catálogo una vez y detecta parecidos sin conexión con palabras_clave.
     */
    public function test_ambos_roles_consultan_el_catalogo_con_su_categoria(): void
    {
        $materialesId = Categoria::where('nombre', 'Materiales')->value('id');
        Concepto::factory()->create(['nombre' => 'Cemento de 50 kg', 'categoria_id' => $materialesId, 'unidad_medida' => 'saco']);
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->getJson('/api/conceptos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.categoria.nombre', 'Materiales')
            ->assertJsonStructure(['data' => ['*' => ['id', 'nombre', 'nombre_normalizado', 'palabras_clave', 'categoria_id', 'unidad_medida', 'categoria' => ['id', 'nombre']]]]);
    }

    /**
     * Cualquier rol agrega conceptos al capturar un gasto; 201 le indica al front
     * que el concepto es nuevo y debe sumarlo a su catálogo local.
     */
    public function test_crear_un_concepto_nuevo_responde_201(): void
    {
        $materialesId = Categoria::where('nombre', 'Materiales')->value('id');
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson('/api/conceptos', ['nombre' => 'Cemento de 50 kg', 'categoria_id' => $materialesId, 'unidad_medida' => 'saco'])
            ->assertCreated()
            ->assertJsonPath('data.unidad_medida', 'saco')
            ->assertJsonPath('data.categoria.nombre', 'Materiales')
            ->assertJsonPath('data.palabras_clave', '50kg cemento');
        $this->assertDatabaseCount('conceptos', 1);
    }

    /**
     * Pagos de mano de obra o servicios no tienen unidad; antes se guardaba "pieza", un dato falso.
     */
    public function test_un_concepto_sin_unidad_se_guarda_con_unidad_null(): void
    {
        $manoDeObraId = Categoria::where('nombre', 'Mano de Obra')->value('id');
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson('/api/conceptos', ['nombre' => 'Fontanero Manuel', 'categoria_id' => $manoDeObraId])
            ->assertCreated()
            ->assertJsonPath('data.unidad_medida', null);
        $this->assertDatabaseHas('conceptos', ['nombre' => 'Fontanero Manuel', 'unidad_medida' => null]);
    }

    /**
     * Escribirlo con otras mayúsculas o unidades ("50 kilos" = "50 kg") no es un error:
     * se devuelve el existente (200) en lugar de crear otro, para no partir las gráficas en dos.
     */
    public function test_crear_uno_que_ya_existe_escrito_distinto_devuelve_el_existente_con_200(): void
    {
        $materialesId = Categoria::where('nombre', 'Materiales')->value('id');
        $existente = Concepto::factory()->create(['nombre' => 'Cemento de 50 kg']);
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson('/api/conceptos', ['nombre' => 'CEMENTO de 50 kilos', 'categoria_id' => $materialesId])
            ->assertOk()
            ->assertJsonPath('data.id', $existente->id);
        $this->assertDatabaseCount('conceptos', 1);
    }

    /**
     * Ante un nombre parecido no se crea nada: el front muestra "¿Quisiste decir…?"
     * con los posibles para que el usuario elija el existente.
     */
    public function test_uno_parecido_responde_409_con_los_posibles_duplicados(): void
    {
        $materialesId = Categoria::where('nombre', 'Materiales')->value('id');
        $existente = Concepto::factory()->create(['nombre' => 'Cemento de 50 kg']);
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson('/api/conceptos', ['nombre' => 'Saco de cemento 50 kg', 'categoria_id' => $materialesId])
            ->assertStatus(409)
            ->assertJsonPath('code', 'POSIBLE_DUPLICADO')
            ->assertJsonPath('posibles.0.id', $existente->id);
        $this->assertDatabaseCount('conceptos', 1);
    }

    /**
     * El usuario ya vio el aviso y eligió crear uno distinto.
     */
    public function test_con_confirmar_nuevo_se_crea_aunque_haya_uno_parecido(): void
    {
        $materialesId = Categoria::where('nombre', 'Materiales')->value('id');
        $existente = Concepto::factory()->create(['nombre' => 'Cemento de 50 kg']);
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson('/api/conceptos', ['nombre' => 'Saco de cemento 50 kg', 'categoria_id' => $materialesId, 'confirmar_nuevo' => true])
            ->assertCreated();
        $this->assertDatabaseCount('conceptos', 2);
    }

    /**
     * Lo capturado sin señal se sincroniza en una sola petición.
     */
    public function test_categoria_nombre_crea_la_categoria_junto_con_el_concepto(): void
    {
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson('/api/conceptos', ['nombre' => 'Impermeabilizante 19 litros', 'categoria_nombre' => 'Impermeabilizantes'])
            ->assertCreated()
            ->assertJsonPath('data.categoria.nombre', 'Impermeabilizantes');
        $this->assertDatabaseHas('categorias', ['nombre' => 'Impermeabilizantes']);
    }

    /**
     * Se reutiliza el concepto existente y no queda una categoría huérfana sin conceptos.
     */
    public function test_si_el_concepto_ya_existia_no_se_crea_la_categoria_nueva(): void
    {
        Concepto::factory()->create(['nombre' => 'Cemento de 50 kg']);
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson('/api/conceptos', ['nombre' => 'cemento de 50 kg', 'categoria_nombre' => 'Categoria inventada'])
            ->assertOk();
        $this->assertDatabaseMissing('categorias', ['nombre' => 'Categoria inventada']);
    }

    /**
     * La categoría se manda de una sola forma: con las dos sería ambiguo cuál usar.
     */
    public function test_mandar_categoria_id_y_categoria_nombre_a_la_vez_responde_422(): void
    {
        $materialesId = Categoria::where('nombre', 'Materiales')->value('id');
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson('/api/conceptos', ['nombre' => 'Pala', 'categoria_id' => $materialesId, 'categoria_nombre' => 'Herramienta'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categoria_id');
    }

    /**
     * Puede corregir el nombre y quitar una unidad puesta por error.
     */
    public function test_el_administrador_corrige_un_concepto(): void
    {
        $concepto = Concepto::factory()->create(['nombre' => 'Fontanero Manuel', 'unidad_medida' => 'pieza']);
        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)->patchJson("/api/conceptos/{$concepto->id}", ['nombre' => 'Fontanero Manuel Lopez', 'unidad_medida' => null])
            ->assertOk()
            ->assertJsonPath('data.unidad_medida', null);
        $this->assertDatabaseHas('conceptos', ['id' => $concepto->id, 'nombre_normalizado' => 'fontanero manuel lopez']);
    }

    /**
     * Si son el mismo se debe usar fusionar; sin esta validación el índice único respondería 500.
     */
    public function test_renombrar_con_el_nombre_de_otro_concepto_responde_422(): void
    {
        Concepto::factory()->create(['nombre' => 'Pala']);
        $otro = Concepto::factory()->create(['nombre' => 'Pico']);
        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)->patchJson("/api/conceptos/{$otro->id}", ['nombre' => 'PALA'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nombre');
    }

    /**
     * Corregir catálogos es solo del Administrador: un renombre cambia todas las
     * gráficas. El Trabajador recibe 403 y el nombre no cambia.
     */
    public function test_un_trabajador_no_puede_corregir_conceptos(): void
    {
        $concepto = Concepto::factory()->create(['nombre' => 'Pala']);
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->patchJson("/api/conceptos/{$concepto->id}", ['nombre' => 'Otra'])
            ->assertForbidden();
        $this->assertDatabaseHas('conceptos', ['id' => $concepto->id, 'nombre' => 'Pala']);
    }
}
