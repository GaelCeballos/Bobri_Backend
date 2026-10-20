<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConceptoRequest;
use App\Http\Requests\UpdateConceptoRequest;
use App\Models\Categoria;
use App\Models\Concepto;
use App\Support\NormalizadorTexto;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catálogo de conceptos (lo que se compra o renta en la obra).
 *
 * - Consultar y crear: ambos roles.
 * - Corregir: solo Administrador.
 * - No hay borrado: los gastos referencian el concepto; para quitar duplicados existirá la función de fusionar conceptos (pendiente).
 *
 * @package App\Http\Controllers
 */
class ConceptoController extends Controller
{
    /**
     * Devuelve el catálogo completo de conceptos, ordenado por nombre.
     *
     * El front lo descarga una sola vez y filtra localmente; with() carga todas las categorías en UNA consulta extra (evita N+1).
     */
    public function index(): JsonResponse
    {
        $conceptos = Concepto::query()
            ->select(['id', 'nombre', 'nombre_normalizado', 'categoria_id', 'unidad_medida'])
            ->with('categoria:id,nombre')
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $conceptos,
        ]);
    }

    /**
     * Busca el concepto por su huella normalizada o lo crea.
     *
     * Escribir "RENTA de Rotomartillo" cuando ya existe "Renta de rotomartillo" no
     * es un error: se devuelve el existente. El código HTTP le indica al front qué
     * pasó: 201 Created = se agregó al catálogo · 200 OK = ya existía.
     *
     * Si el concepto ya existe se devuelve tal cual, SIN crear la categoría nueva
     * que pudiera venir en `categoria_nombre` (evita categorías huérfanas).
     */
    public function store(StoreConceptoRequest $request): JsonResponse
    {
        $datos = $request->validated();
        
        // Paso 1: verificar si el concepto ya existe
        $existente = Concepto::where('nombre_normalizado', NormalizadorTexto::normalizar($datos['nombre']))
            ->with('categoria:id,nombre')
            ->first();

        if ($existente) {
            return response()->json([
                'status' => 'success',
                'message' => 'El concepto ya existía; se usará el existente.',
                'data' => $existente,
            ], Response::HTTP_OK);
        }

        // Paso 2: crear la categoría (si vino categoria_nombre) y el concepto en una
        // transacción: si falla el concepto, no queda una categoría creada a medias
        $concepto = DB::transaction(function () use ($datos): Concepto {
            $categoriaId = $datos['categoria_id']
                ?? Categoria::buscarOCrear($datos['categoria_nombre'])->id;

            return Concepto::buscarOCrear($datos['nombre'], [
                'categoria_id' => $categoriaId,
                ...Arr::only($datos, ['unidad_medida']),
            ]);
        });

        // Puede ser false si otra persona creó el mismo concepto entre el paso 1 y el 2
        $creado = $concepto->wasRecentlyCreated;
        if ($creado) {
            $concepto->refresh(); // unidad_medida 'pieza' la pone la BD por defecto
        }
        $concepto->load('categoria:id,nombre');

        return response()->json([
            'status' => 'success',
            'message' => $creado ? 'Concepto creado.' : 'El concepto ya existía; se usará el existente.',
            'data' => $concepto,
        ], $creado ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * Actualiza un concepto (solo Administrador; lo verifican el middleware de la
     * ruta y UpdateConceptoRequest). Como los gastos lo referencian por id, el
     * nombre nuevo aparece de inmediato en todas las gráficas.
     */
    public function update(UpdateConceptoRequest $request, Concepto $concepto): JsonResponse
    {
        $concepto->update($request->validated());
        $concepto->load('categoria:id,nombre');

        return response()->json([
            'status' => 'success',
            'message' => 'Concepto actualizado.',
            'data' => $concepto,
        ]);
    }
}
