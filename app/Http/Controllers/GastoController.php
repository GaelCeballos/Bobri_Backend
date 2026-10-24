<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGastoRequest;
use App\Http\Requests\UpdateGastoRequest;
use App\Models\Gasto;
use App\Support\NormalizadorTexto;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gastos: cada compra o pago registrado en una obra (captura manual, con o sin QR).
 *
 * - Registrar y ver los propios (`/gastos/mios`): Administrador y Trabajador.
 * - Ver todos con filtros (pantalla "Control y Auditoría de Gastos"): solo Administrador.
 * - Corregir y borrar: el Administrador o quien lo registró ({@see \App\Policies\GastoPolicy}).
 * - Borrar es soft delete: deja de contar, pero el admin lo ve con `?eliminados=1`.
 *
 * @package App\Http\Controllers
 */
class GastoController extends Controller
{
    /**
     * Relaciones que el front necesita para mostrar un gasto, solo con los campos necesarios.
     * La obra va sin importe del contrato porque el trabajador también recibe sus gastos.
     */
    private const RELACIONES = [
        'obra:id,nombre',
        'concepto:id,nombre,categoria_id',
        'concepto.categoria:id,nombre',
        'user:id,name',
        'actualizadoPor:id,name',
        'eliminadoPor:id,name',
    ];

    /**
     * Lista de todos los gastos con los filtros de la pantalla de auditoría (solo Administrador).
     *
     * Paginada (50 por página por defecto), del más reciente al más antiguo. `meta.suma_monto`
     * es la suma de TODOS los gastos que cumplen los filtros (no solo de la página), para el
     * total que muestra la pantalla.
     * `buscar` busca en el nombre del concepto (normalizado: "cemento" encuentra "CEMENTO 50kg"),
     * en el RFC del proveedor y en el folio fiscal.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Gasto::class);

        $filtros = $request->validate([
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'obra_id' => ['sometimes', 'integer'],
            'user_id' => ['sometimes', 'integer'],
            'concepto_id' => ['sometimes', 'integer'],
            'tipo_pago' => ['sometimes', Rule::in(['Efectivo', 'Tarjeta', 'Transferencia'])],
            'buscar' => ['sometimes', 'string', 'max:100'],
            'eliminados' => ['sometimes', 'boolean'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Gasto::query()
            ->when($request->boolean('eliminados'), fn (Builder $q) => $q->onlyTrashed())
            ->when($filtros['desde'] ?? null, fn (Builder $q, string $desde) => $q->where('fecha_gasto', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, string $hasta) => $q->where('fecha_gasto', '<=', $hasta))
            ->when($filtros['obra_id'] ?? null, fn (Builder $q, int $id) => $q->where('obra_id', $id))
            ->when($filtros['user_id'] ?? null, fn (Builder $q, int $id) => $q->where('user_id', $id))
            ->when($filtros['concepto_id'] ?? null, fn (Builder $q, int $id) => $q->where('concepto_id', $id))
            ->when($filtros['tipo_pago'] ?? null, fn (Builder $q, string $tipo) => $q->where('tipo_pago', $tipo))
            ->when($filtros['buscar'] ?? null, fn (Builder $q, string $texto) => $this->buscar($q, $texto));

        // La suma se calcula antes de paginar y sin orden: una sola consulta SUM en la base
        $suma = (clone $query)->sum('monto_total');

        $pagina = $this->conRelaciones($query)
            ->orderByDesc('fecha_gasto')
            ->orderByDesc('id')
            ->paginate($filtros['por_pagina'] ?? 50);

        return $this->respuestaPaginada($pagina, ['suma_monto' => number_format((float) $suma, 2, '.', '')]);
    }

    /**
     * Gastos que registró el usuario autenticado (historial del trabajador).
     *
     * Paginados (50 por página), del más reciente al más antiguo; `?desde=&hasta=` opcionales.
     * No incluye los borrados.
     */
    public function mios(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);

        $pagina = $this->conRelaciones(Gasto::query())
            ->where('user_id', $request->user()->getKey())
            ->when($filtros['desde'] ?? null, fn (Builder $q, string $desde) => $q->where('fecha_gasto', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, string $hasta) => $q->where('fecha_gasto', '<=', $hasta))
            ->orderByDesc('fecha_gasto')
            ->orderByDesc('id')
            ->paginate(50);

        return $this->respuestaPaginada($pagina);
    }

    /**
     * Registra un gasto.
     *
     * - Reenvío del mismo `uuid_cliente` (el teléfono perdió la respuesta y reintenta):
     *   200 con el gasto ya creado, sin duplicarlo. Si ese `uuid_cliente` es de un gasto de
     *   OTRO usuario: 409, sin mostrar ese gasto.
     * - Factura (QR) ya registrada en otro gasto: 409 `FACTURA_DUPLICADA`.
     * - Si el monto no coincide con el total de la factura, se registra igual (puede ser un
     *   pago parcial) pero se devuelve `advertencia`.
     * Respuesta: 201 con el gasto.
     */
    public function store(StoreGastoRequest $request): JsonResponse
    {
        $usuario = $request->user();
        $factura = $request->factura();

        if ($respuesta = $this->respuestaSiYaExiste($request->validated('uuid_cliente'), $usuario->getKey())) {
            return $respuesta;
        }

        if ($factura && $respuesta = $this->respuestaSiFacturaDuplicada($factura->uuid)) {
            return $respuesta;
        }

        $datos = [
            ...$request->validated(),
            'user_id' => $usuario->getKey(),
            'uuid_sat' => $factura?->uuid,
            'rfc_emisor' => $factura?->rfcEmisor,
        ];

        try {
            // En su propia transacción: en PostgreSQL un INSERT fallido invalida la transacción
            // en curso; así solo se deshace este intento y se puede consultar qué pasó
            $gasto = DB::transaction(fn () => Gasto::create($datos));
        } catch (UniqueConstraintViolationException) {
            // Dos envíos llegaron al mismo tiempo y el otro ganó por milisegundos: las
            // revisiones de arriba ya pasaron, pero el índice único lo detecta. Se responde
            // igual que si hubiera llegado después
            return $this->respuestaSiYaExiste($datos['uuid_cliente'], $usuario->getKey())
                ?? ($factura ? $this->respuestaSiFacturaDuplicada($factura->uuid) : null)
                ?? throw new \RuntimeException('Violación de índice único no esperada al registrar un gasto.');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Gasto registrado.',
            'advertencia' => $this->advertenciaMonto($gasto, $factura?->total),
            'data' => $this->cargar($gasto),
        ], Response::HTTP_CREATED);
    }

    /**
     * Corrige un gasto (el Administrador o quien lo registró: lo revisa UpdateGastoRequest).
     *
     * Guarda quién lo corrigió en `actualizado_por` (`updated_at` dice cuándo). Si se manda
     * `qr_payload: null`, se quita la factura del gasto.
     */
    public function update(UpdateGastoRequest $request, Gasto $gasto): JsonResponse
    {
        $datos = $request->validated();
        $factura = $request->factura();

        if (array_key_exists('qr_payload', $datos)) {
            if ($factura && $respuesta = $this->respuestaSiFacturaDuplicada($factura->uuid, $gasto->getKey())) {
                return $respuesta;
            }

            $datos['uuid_sat'] = $factura?->uuid;
            $datos['rfc_emisor'] = $factura?->rfcEmisor;
        }

        $gasto->fill($datos);
        $gasto->actualizado_por = $request->user()->getKey();

        try {
            DB::transaction(fn () => $gasto->save());
        } catch (UniqueConstraintViolationException) {
            // Otra petición registró la misma factura entre la revisión y el guardado
            return ($factura ? $this->respuestaSiFacturaDuplicada($factura->uuid, $gasto->getKey()) : null)
                ?? throw new \RuntimeException('Violación de índice único no esperada al corregir un gasto.');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Gasto actualizado.',
            'advertencia' => $this->advertenciaMonto($gasto, $factura?->total),
            'data' => $this->cargar($gasto),
        ]);
    }

    /**
     * Borra un gasto (soft delete): deja de contar en totales y dashboard, y el admin lo ve
     * en `GET /gastos?eliminados=1` con quién lo borró.
     */
    public function destroy(Request $request, Gasto $gasto): JsonResponse
    {
        Gate::authorize('delete', $gasto);

        // En una transacción: si el borrado fallara, no queda `eliminado_por` en un gasto vivo
        DB::transaction(function () use ($request, $gasto): void {
            $gasto->eliminado_por = $request->user()->getKey();
            $gasto->save();
            $gasto->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Gasto eliminado.',
        ]);
    }

    /**
     * Si ya existe un gasto con ese `uuid_cliente`, la respuesta a devolver; si no, null.
     *
     * Incluye los borrados: si el gasto se creó y luego se borró, un reenvío tardío no debe
     * volver a crearlo.
     */
    private function respuestaSiYaExiste(string $uuidCliente, int $usuarioId): ?JsonResponse
    {
        $existente = Gasto::withTrashed()->where('uuid_cliente', $uuidCliente)->first();

        if (! $existente) {
            return null;
        }

        // Un uuid_cliente ajeno no debe revelar el gasto de otra persona
        if ((int) $existente->user_id !== $usuarioId) {
            return response()->json([
                'status' => 'error',
                'code' => 'UUID_CLIENTE_EN_USO',
                'message' => 'El identificador de esta captura ya está en uso. Vuelve a capturar el gasto.',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'El gasto ya estaba registrado.',
            'data' => $this->cargar($existente),
        ]);
    }

    /**
     * Si la factura ya está en otro gasto vigente, la respuesta 409; si no, null.
     *
     * Devuelve solo lo necesario para que el usuario identifique el gasto existente (fecha,
     * monto, obra y quién lo registró), no el gasto completo.
     */
    private function respuestaSiFacturaDuplicada(string $uuidSat, ?int $excepto = null): ?JsonResponse
    {
        $existente = Gasto::with(['obra:id,nombre', 'user:id,name'])
            ->where('uuid_sat', $uuidSat)
            ->when($excepto, fn (Builder $q, int $id) => $q->whereKeyNot($id))
            ->first();

        if (! $existente) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'code' => 'FACTURA_DUPLICADA',
            'message' => 'Esta factura ya está registrada.',
            'gasto_existente' => [
                'id' => $existente->getKey(),
                'fecha_gasto' => $existente->fecha_gasto->toDateString(),
                'monto_total' => $existente->monto_total,
                'obra' => $existente->obra?->nombre,
                'registrado_por' => $existente->user?->name,
            ],
        ], Response::HTTP_CONFLICT);
    }

    /**
     * Aviso si el monto capturado no coincide con el total de la factura (null si coincide
     * o no hay factura). No bloquea: puede ser un pago parcial o un error de dedo.
     */
    private function advertenciaMonto(Gasto $gasto, ?string $totalFactura): ?string
    {
        // Se comparan centavos enteros: comparar decimales como float puede fallar por redondeo
        $centavos = fn (string $monto): int => (int) round((float) $monto * 100);

        if ($totalFactura === null || $centavos((string) $gasto->monto_total) === $centavos($totalFactura)) {
            return null;
        }

        return "El monto capturado (\${$gasto->monto_total}) no coincide con el total de la factura (\${$totalFactura}).";
    }

    /**
     * Búsqueda libre del admin en concepto, RFC del proveedor y folio fiscal.
     *
     * El concepto se busca por su huella normalizada (sin acentos ni mayúsculas) y debe
     * contener TODAS las palabras, en cualquier orden: "CEMENTO 50kg" encuentra "Cemento de
     * 50 kg" (igual que las sugerencias del front). Los comodines de LIKE (`%`, `_`) que
     * escriba el usuario se escapan: "50%" busca el texto "50%", no "todo lo que empiece con 50".
     */
    private function buscar(Builder $query, string $texto): Builder
    {
        $escapar = fn (string $valor): string => addcslashes($valor, '\\%_');
        // Un texto de solo símbolos ("%", "---") no deja palabras, y LIKE '%%' coincidiría
        // con TODOS los conceptos: en ese caso no se busca por concepto
        $palabras = array_filter(explode(' ', NormalizadorTexto::normalizar($texto)));
        $literal = $escapar(mb_strtoupper(trim($texto)));

        return $query->where(function (Builder $q) use ($palabras, $escapar, $literal): void {
            if ($palabras !== []) {
                $q->whereHas('concepto', function (Builder $c) use ($palabras, $escapar): void {
                    foreach ($palabras as $palabra) {
                        $c->where('nombre_normalizado', 'like', '%'.$escapar($palabra).'%');
                    }
                });
            }

            $q->orWhere('rfc_emisor', 'like', "%{$literal}%")
                ->orWhereRaw('UPPER(uuid_sat::text) LIKE ?', ["%{$literal}%"]);
        });
    }

    /**
     * Carga {@see self::RELACIONES} en la MISMA consulta de la lista, en lugar de una
     * consulta por gasto (N+1).
     */
    private function conRelaciones(Builder $query): Builder
    {
        return $query->with(self::RELACIONES);
    }

    /**
     * Un gasto con las mismas relaciones que las listas.
     */
    private function cargar(Gasto $gasto): Gasto
    {
        return $gasto->load(self::RELACIONES);
    }

    /**
     * Envoltura de las listas paginadas: `data` con los gastos y `meta` con la paginación.
     *
     * @param array<string, mixed> $extra Datos adicionales para `meta` (p. ej. la suma).
     */
    private function respuestaPaginada(LengthAwarePaginator $pagina, array $extra = []): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $pagina->items(),
            'meta' => [
                'pagina' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'por_pagina' => $pagina->perPage(),
                'total' => $pagina->total(),
                ...$extra,
            ],
        ]);
    }
}
