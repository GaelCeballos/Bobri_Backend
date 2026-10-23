<?php

namespace App\Http\Controllers;

use App\Http\Requests\FusionarConceptoRequest;
use App\Http\Requests\StoreConceptoRequest;
use App\Http\Requests\UpdateConceptoRequest;
use App\Models\Categoria;
use App\Models\Concepto;
use App\Models\Gasto;
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
 * - Fusionar duplicados ("Compra de pala" → "Pala"): solo Administrador.
 * - No hay borrado directo: los gastos referencian el concepto; un duplicado se
 *   elimina fusionándolo, para que sus gastos no se pierdan.
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
            ->select(['id', 'nombre', 'nombre_normalizado', 'palabras_clave', 'categoria_id', 'unidad_medida'])
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
     * pasó: 201 Created = se agregó al catálogo · 200 OK = ya existía ·
     * 409 Conflict = hay uno parecido; reenviar con `confirmar_nuevo: true` si de
     * verdad es distinto.
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

        // Capa 2 contra duplicados: si hay conceptos PARECIDOS (mismas palabras clave,
        // p. ej. "Saco de cemento 25 kg" vs "Cemento saco 25 kg") y el usuario no
        // confirmó, no se crea nada: se devuelven los candidatos para que el front
        // pregunte "¿Quisiste decir…?". Protege aunque el front falle o se salte el aviso.
        if (! $request->boolean('confirmar_nuevo')) {
            $parecidos = Concepto::where('palabras_clave', NormalizadorTexto::palabrasClave($datos['nombre']))
                ->with('categoria:id,nombre')
                ->get(['id', 'nombre', 'categoria_id', 'unidad_medida']);

            if ($parecidos->isNotEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'POSIBLE_DUPLICADO',
                    'message' => "¿Quisiste decir «{$parecidos->first()->nombre}»? Si es un concepto distinto, confirma que quieres crearlo.",
                    'posibles' => $parecidos,
                ], Response::HTTP_CONFLICT);
            }
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
            // Si no se envió unidad_medida, el modelo en memoria no tiene ese campo y la
            // respuesta lo omitiría; refresh() lo relee de la BD para que llegue como null
            $concepto->refresh();
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

    /**
     * Fusiona un concepto duplicado (el de la ruta) en otro concepto destino.
     *
     * Caso típico: "Compra de pala" y "Pala" son lo mismo, pero como se escriben
     * distinto la normalización no los une y las gráficas los suman por separado.
     * Al fusionar "Compra de pala" → "Pala":
     *  1. Todos los gastos de "Compra de pala" pasan a "Pala". Conservan monto,
     *     fecha, obra y trabajador; solo cambia a qué concepto apuntan.
     *  2. "Compra de pala" se borra del catálogo para que nadie vuelva a elegirlo.
     *
     * Todo ocurre en una transacción: si algo falla, no se mueve ningún gasto.
     * Solo Administrador (middleware de la ruta + FusionarConceptoRequest).
     *
     * Si las unidades de medida eran distintas (p. ej. "saco" y "kg", o "saco" y
     * sin unidad) la fusión se hace igual, pero se devuelve una advertencia porque
     * las cantidades podrían dejar de ser comparables.
     */
    public function fusionar(FusionarConceptoRequest $request, Concepto $concepto): JsonResponse
    {
        $destino = Concepto::with('categoria:id,nombre')->findOrFail($request->validated('destino_id'));

        $movidos = DB::transaction(function () use ($concepto, $destino): int {
            // Bloquea la fila del origen (SELECT … FOR UPDATE). En PostgreSQL, un gasto
            // nuevo que intente usar este concepto en ese mismo instante espera a que
            // termine la fusión, así nunca queda un gasto apuntando a un concepto borrado.
            $origen = Concepto::whereKey($concepto->getKey())->lockForUpdate()->firstOrFail();

            // Un solo UPDATE para todos los gastos (no uno por gasto)
            $movidos = Gasto::where('concepto_id', $origen->getKey())
                ->update(['concepto_id' => $destino->getKey()]);

            $origen->delete();

            return $movidos;
        });

        // Un concepto sin unidad (null) se muestra como "sin unidad" en lugar de «»
        $unidadOrigen = $concepto->unidad_medida ?? 'sin unidad';
        $unidadDestino = $destino->unidad_medida ?? 'sin unidad';

        $advertencia = $concepto->unidad_medida !== $destino->unidad_medida
            ? "Las unidades eran distintas («{$unidadOrigen}» y «{$unidadDestino}»); revisa las cantidades de los gastos movidos."
            : null;

        return response()->json([
            'status' => 'success',
            'message' => "Se movieron {$movidos} gasto(s) a «{$destino->nombre}» y se eliminó «{$concepto->nombre}».",
            'data' => [
                'concepto' => $destino,
                'gastos_movidos' => $movidos,
                'advertencia' => $advertencia,
            ],
        ]);
    }

    /**
     * Reporte de posibles duplicados para el Administrador (capa 3).
     *
     * Agrupa los conceptos que comparten palabras clave ("Cemento saco 25 kg" y
     * "Saco de cemento 25 kg") para revisarlos y fusionarlos con un clic. Atrapa lo
     * que pasó el aviso del front y el 409 del backend, p. ej. cuando alguien
     * confirmó "crear nuevo" por error. Solo Administrador (middleware de la ruta).
     *
     * Cada grupo trae `sugerido_destino_id`: el concepto con más gastos, porque es el
     * nombre que más se usa y conservarlo mueve menos gastos.
     */
    public function posiblesDuplicados(): JsonResponse
    {
        // Subconsulta de firmas que aparecen más de una vez: la base de datos resuelve
        // todo en UNA consulta y aprovecha el índice de palabras_clave
        $firmasRepetidas = Concepto::query()
            ->select('palabras_clave')
            ->groupBy('palabras_clave')
            ->havingRaw('COUNT(*) > 1');

        // withCount agrega `gastos_count` en la misma consulta (sin N+1); ordenar por él
        // deja primero, dentro de cada grupo, al concepto más usado
        $conceptos = Concepto::query()
            ->whereIn('palabras_clave', $firmasRepetidas)
            ->select(['id', 'nombre', 'palabras_clave', 'categoria_id', 'unidad_medida', 'created_at'])
            ->with('categoria:id,nombre')
            ->withCount('gastos')
            ->orderBy('palabras_clave')
            ->orderByDesc('gastos_count')
            ->get();

        $grupos = $conceptos->groupBy('palabras_clave')
            ->map(fn ($grupo, string $firma): array => [
                'palabras_clave' => $firma,
                'sugerido_destino_id' => $grupo->first()->id,
                'conceptos' => $grupo->values(),
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $grupos,
        ]);
    }
}
