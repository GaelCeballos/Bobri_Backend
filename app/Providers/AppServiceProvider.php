<?php

namespace App\Providers;

use App\Contracts\LectorTicket;
use App\Support\Lectores\LectorTicketFalso;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Qué lector de tickets recibe el Job LeerTicket, según LECTOR_TICKETS en el .env.
        // Un valor desconocido falla en lugar de caer en el falso: en producción eso
        // guardaría datos inventados como si se hubieran leído de la foto
        $this->app->bind(LectorTicket::class, fn () => match (config('services.lector_tickets.driver')) {
            'falso' => new LectorTicketFalso(),
            default => throw new InvalidArgumentException('LECTOR_TICKETS no es un lector de tickets válido.'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
