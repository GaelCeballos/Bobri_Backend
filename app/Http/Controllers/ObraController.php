<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreObraRequest;
use App\Http\Requests\UpdateObraRequest;
use App\Models\Obra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Obras (proyectos de construcción con su importe de contrato).
 *
 * - Consultar la lista: Administrador y Trabajador (el trabajador elige la obra al capturar un gasto).
 * - Detalle, alta y edición: solo Administrador.
 * - No hay borrado (DELETE responde 405): al terminar, la obra se marca Finalizada. Así sus
 *   gastos conservan su obra; la base de datos lo impide de todos modos (`gastos.obra_id`
 *   con `restrictOnDelete`).
 *
 * @package App\Http\Controllers
 */
class ObraController extends Controller
{
    /**
     * Lista de obras, ordenada por nombre.
     *
     * - Administrador: todas, con todos sus datos (opcionalmente `?estado=Activa|Finalizada`).
     * - Trabajador: solo las obras Activa y sin el importe del contrato. Le basta para elegir
     *   la obra al capturar, y el importe es información financiera que solo maneja el
     *   Administrador (aunque el front lo ocultara, viajaría en la respuesta).
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['sometimes', Rule::in(['Activa', 'Finalizada'])],
        ]);

        $query = Obra::query()->orderBy('nombre');

        if ($request->user()->isAdmin()) {
            $query->select(['id', 'nombre', 'cliente', 'ubicacion', 'importe_contrato', 'estado', 'created_at', 'updated_at']);

            if (isset($filtros['estado'])) {
                $query->where('estado', $filtros['estado']);
            }
        } else {
            // El trabajador no puede capturar gastos en obras finalizadas: ni siquiera se le muestran
            $query->select(['id', 'nombre', 'cliente', 'ubicacion'])->where('estado', 'Activa');
        }

        return response()->json(['status' => 'success', 'data' => $query->get()]);
    }

    /**
     * Detalle de una obra (solo Administrador).
     * Las cifras de gastos (acumulado, saldo, semáforo) se agregarán en la rama del dashboard.
     */
    public function show(Obra $obra): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $obra]);
    }

    /**
     * Da de alta una obra (solo Administrador).
     */
    public function store(StoreObraRequest $request): JsonResponse
    {
        $obra = Obra::create($request->validated());

        // Si no se envió `estado`, la base de datos pone 'Activa', pero el modelo en
        // memoria no lo sabe; refresh() lo relee para que la respuesta no lleve `estado: null`
        $obra->refresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Obra creada.',
            'data' => $obra,
        ], Response::HTTP_CREATED);
    }

    /**
     * Edita los datos de una obra o su estado (solo Administrador).
     * Marcarla Finalizada la quita de la lista del trabajador; se puede volver a activar.
     */
    public function update(UpdateObraRequest $request, Obra $obra): JsonResponse
    {
        $obra->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Obra actualizada.',
            'data' => $obra,
        ]);
    }
}
