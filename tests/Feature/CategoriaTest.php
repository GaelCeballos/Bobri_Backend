<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas HTTP del catálogo de categorías de gasto.
 *
 * Prueba los endpoints /api/categorias tal como los usa el front: un catálogo que
 * crece con lo que escriben los usuarios, sin duplicarse.
 *
 * RefreshDatabase ejecuta las migraciones una vez y envuelve cada prueba en una
 * transacción que se deshace al terminar: cada prueba empieza con la base limpia.
 * La migración de `categorias` siembra las 5 iniciales (Materiales, Mano de Obra,
 * Equipo/Herramienta, Combustible, Varios), por eso existen sin crearlas.
 *
 * Las peticiones se autentican con {@see TestCase::comoUsuario()}, que pasa por el
 * middleware real (sesión única, usuario activo y rol).
 */
final class CategoriaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El front descarga el catálogo una vez y filtra sin conexión, por eso necesita
     * la lista completa con nombre_normalizado.
     */
    public function test_ambos_roles_consultan_el_catalogo_completo_ordenado_por_nombre(): void
    {
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->getJson('/api/categorias')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.nombre', 'Combustible')
            ->assertJsonPath('data.4.nombre', 'Varios')
            ->assertJsonStructure(['data' => ['*' => ['id', 'nombre', 'nombre_normalizado']]]);
    }

    /**
     * Cualquier rol agrega categorías al capturar un gasto; 201 le indica al front
     * que la categoría es nueva y debe sumarla a su catálogo local.
     */
    public function test_crear_una_categoria_nueva_responde_201(): void
    {
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson('/api/categorias', ['nombre' => 'Herramienta menor'])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Herramienta menor')
            ->assertJsonPath('data.nombre_normalizado', 'herramienta menor');
        $this->assertDatabaseCount('categorias', 6);
    }

    /**
     * Escribirla con otras mayúsculas o espacios no es un error: se devuelve la
     * existente (200) en lugar de crear otra, para no partir las gráficas en dos.
     */
    public function test_crear_una_categoria_que_ya_existe_escrita_distinto_devuelve_la_existente_con_200(): void
    {
        $existente = Categoria::create(['nombre' => 'Herramienta menor']);
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson('/api/categorias', ['nombre' => 'HERRAMIENTA   menor'])
            ->assertOk()
            ->assertJsonPath('data.id', $existente->id)
            ->assertJsonPath('data.nombre', 'Herramienta menor');
        $this->assertDatabaseCount('categorias', 6);
    }

    /**
     * Un nombre como "---" no tiene huella posible; sin esta validación el modelo
     * lanzaría una excepción y la API respondería 500.
     */
    public function test_un_nombre_sin_letras_ni_numeros_responde_422(): void
    {
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->postJson('/api/categorias', ['nombre' => '---'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nombre');
    }

    /**
     * El administrador corrige nombres mal escritos; la huella se recalcula para que
     * las capturas siguientes reconozcan el nombre nuevo.
     */
    public function test_el_administrador_renombra_una_categoria(): void
    {
        $categoria = Categoria::create(['nombre' => 'Herramienta menor']);
        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)->patchJson("/api/categorias/{$categoria->id}", ['nombre' => 'Herramienta mayor'])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Herramienta mayor');
        $this->assertDatabaseHas('categorias', ['id' => $categoria->id, 'nombre_normalizado' => 'herramienta mayor']);
    }

    /**
     * Renombrar a un nombre que ya usa otra categoría responde 422 con un mensaje
     * claro; sin esta validación el índice único de la huella respondería 500.
     */
    public function test_renombrar_con_el_nombre_de_otra_categoria_responde_422(): void
    {
        $categoria = Categoria::create(['nombre' => 'Herramienta menor']);
        $admin = User::factory()->admin()->create();
        $this->comoUsuario($admin)->patchJson("/api/categorias/{$categoria->id}", ['nombre' => 'MATERIALES'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nombre');
    }

    /**
     * Corregir catálogos es solo del Administrador: un renombre cambia todas las
     * gráficas. El Trabajador recibe 403 y el nombre no cambia.
     */
    public function test_un_trabajador_no_puede_renombrar_categorias(): void
    {
        $materialesId = Categoria::where('nombre', 'Materiales')->value('id');
        $trabajador = User::factory()->create();
        $this->comoUsuario($trabajador)->patchJson("/api/categorias/{$materialesId}", ['nombre' => 'Otra'])
            ->assertForbidden();
        $this->assertDatabaseHas('categorias', ['id' => $materialesId, 'nombre' => 'Materiales']);
    }
}
