<?php

namespace App\Support;

use App\Models\Gasto;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Collection;

/**
 * Busca gastos capturados A MANO que podrían ser la misma compra que un ticket.
 *
 * Caso típico: el trabajador capturó "Cemento, $1,850" a mano y además subió la foto del
 * ticket de esa compra; al validar el ticket, la compra contaría dos veces. Un gasto se
 * considera posible duplicado si es de la misma obra, del mismo día y su monto es igual al
 * de algún renglón o al total del ticket.
 *
 * Es solo un AVISO para el Administrador: él decide si es duplicado (borra uno) o no.
 *
 * Rendimiento: usa el índice `gastos (obra_id, fecha_gasto)`; solo revisa los gastos de una
 * obra en un día (medido: 0.04 ms con 100 mil gastos).
 *
 * @package App\Support
 */
final class PosiblesDuplicados
{
    /** Tope de avisos: más que esto no ayuda al admin a decidir */
    private const MAXIMO = 20;

    /**
     * @return Collection<int, Gasto> Gastos manuales que coinciden, con quién los capturó y su concepto.
     */
    public static function delTicket(Ticket $ticket): Collection
    {
        $montos = $ticket->renglones()->whereNotNull('monto')->pluck('monto')
            ->push($ticket->total)
            ->filter()
            ->unique()
            ->values();

        if ($montos->isEmpty()) {
            return new Collection();
        }

        return Gasto::query()
            ->where('obra_id', $ticket->obra_id)
            ->where('fecha_gasto', self::fecha($ticket))
            // Solo capturas manuales: los gastos de otro ticket ya se revisaron al validarlo
            ->whereNull('ticket_id')
            ->whereIn('monto_total', $montos)
            ->with(['user:id,name', 'concepto:id,nombre'])
            ->orderBy('id')
            ->limit(self::MAXIMO)
            ->get(['id', 'obra_id', 'concepto_id', 'user_id', 'monto_total', 'fecha_gasto', 'created_at']);
    }

    /**
     * Día con el que se comparará: el del ticket o, si no se leyó, el día en que se subió la
     * foto (el mismo que tendrán sus gastos al validar). `created_at` está en UTC: se pasa a
     * la hora de México para no tomar el día siguiente en las capturas de la noche.
     */
    private static function fecha(Ticket $ticket): string
    {
        return $ticket->fecha_compra?->toDateString()
            ?? $ticket->created_at->toImmutable()->setTimezone(ZonaHoraria::POR_DEFECTO)->toDateString();
    }
}
