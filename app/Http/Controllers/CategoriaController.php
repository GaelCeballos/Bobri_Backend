<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoriaRequest;
use App\Http\Requests\UpdateCategoriaRequest;
use App\Models\Categoria;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catálogo de categorías de gasto.
 *
 * - Consultar y crear: Administrador y Trabajador (al capturar un gasto).
 * - Renombrar: solo Administrador.
 * - No hay borrado: los conceptos referencian su categoría y la base de datos
 *   lo impide (`restrictOnDelete`); borrarla dejaría gastos sin clasificar.
 *
 * @package App\Http\Controllers
 */
class CategoriaController extends Controller
{
    /**
     * Devuelve el catálogo completo de categorías, ordenado por nombre.
     *
     * El front lo descarga una sola vez (y lo guarda para trabajar sin señal) y
     * filtra en el teléfono mientras el usuario escribe, en lugar de consultar la
     * API en cada tecla. Se incluye `nombre_normalizado` para que el front pueda
     * detectar coincidencias igual que el backend.
     */
    public function index(): JsonResponse
    {
        $categorias = Categoria::query()
            ->select(['id', 'nombre', 'nombre_normalizado'])
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $categorias,
        ]);
    }

    /**
     * Busca la categoría por su huella normalizada o la crea.
     *
     * Escribir "HERRAMIENTA  menor" cuando ya existe "Herramienta menor" no es
     * un error: se devuelve la existente. El código HTTP le indica al front qué pasó:
     * 201 Created = se agregó al catálogo · 200 OK = ya existía.
     */
    public function store(StoreCategoriaRequest $request): JsonResponse
    {
        $categoria = Categoria::buscarOCrear($request->validated('nombre'));
        $creada = $categoria->wasRecentlyCreated;

        return response()->json([
            'status' => 'success',
            'message' => $creada ? 'Categoría creada.' : 'La categoría ya existía; se usará la existente.',
            'data' => $categoria,
        ], $creada ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * Renombra una categoría (solo Administrador; lo verifican el middleware de la
     * ruta y UpdateCategoriaRequest). Como los conceptos la referencian por id, el
     * nombre nuevo aparece de inmediato en todas las gráficas.
     */
    public function update(UpdateCategoriaRequest $request, Categoria $categoria): JsonResponse
    {
        $categoria->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Categoría actualizada.',
            'data' => $categoria,
        ]);
    }
}
