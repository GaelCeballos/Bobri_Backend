<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets por foto: una compra de varios productos capturada con UNA foto del ticket.
 *
 * Flujo: el trabajador sube la foto (estado `procesando`) → una tarea en cola lee los
 * productos y los guarda como renglones (`por_revisar`) → el Administrador los corrige
 * viendo la foto → al validar, cada renglón se convierte en un gasto (`validado`), o el
 * ticket se rechaza y su foto se borra (`rechazado`). Si la lectura falla: `error`.
 *
 * Mientras no se valida, nada de esto cuenta en el dashboard: los gastos aún no existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            // Los registros referenciados no se borran (obras se finalizan, usuarios se desactivan)
            $table->foreignId('obra_id')->constrained('obras')->restrictOnDelete();
            // Quien tomó la foto: será el `user_id` de los gastos (es quien hizo la compra)
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // La elige quien captura; solo se asigna a los conceptos NUEVOS que se creen al validar
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->enum('tipo_pago', ['Efectivo', 'Tarjeta', 'Transferencia']);
            // Igual que en gastos: si la subida sin señal se reintenta, no se duplica el ticket
            $table->uuid('uuid_cliente')->unique();
            // Día en que se tomó la foto, según el teléfono. NO es `created_at`: sin señal, la foto
            // puede subirse días después. Es la fecha de los gastos si no se lee la del ticket
            $table->date('fecha_captura');

            // Ruta en el disco `tickets` (Cloudflare R2 en producción), nunca la imagen.
            // null cuando el ticket se rechaza: la foto se borra
            $table->string('ruta_foto', 255)->nullable();
            $table->enum('estado', ['procesando', 'por_revisar', 'validado', 'rechazado', 'error'])
                ->default('procesando');

            // Datos del encabezado leídos de la foto; el admin los corrige al revisar
            $table->date('fecha_compra')->nullable();
            $table->string('proveedor', 200)->nullable();
            $table->string('rfc_emisor', 13)->nullable();
            $table->decimal('total', 12, 2)->nullable();

            // Factura del TICKET completo (QR opcional), no de cada renglón
            $table->text('qr_payload')->nullable();
            $table->uuid('uuid_sat')->nullable();
            $table->string('url_pdf', 500)->nullable();
            $table->string('url_xml', 500)->nullable();

            // Por qué falló la lectura (estado `error`), para que el admin decida
            $table->text('error_lectura')->nullable();
            // Lo ve el trabajador en su historial ("Foto borrosa, vuelve a tomarla")
            $table->string('motivo_rechazo', 500)->nullable();
            // El Administrador que validó o rechazó, y cuándo
            $table->foreignId('revisado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revisado_en')->nullable();
            $table->timestamps();

            // Bandeja del admin (por estado, los más antiguos primero) e historial del trabajador
            $table->index(['estado', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('obra_id');
        });

        // La misma factura no se registra en dos tickets vigentes (un rechazado la libera)
        DB::statement("CREATE UNIQUE INDEX tickets_uuid_sat_unique ON tickets (uuid_sat) WHERE estado <> 'rechazado'");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_rechazo_con_motivo
            CHECK (estado <> 'rechazado' OR motivo_rechazo IS NOT NULL)");
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_total_positivo CHECK (total IS NULL OR total > 0)');

        Schema::create('ticket_renglones', function (Blueprint $table) {
            $table->id();
            // Los renglones son parte del ticket
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            // Orden en que aparecen en el ticket, para mostrarlos igual que en la foto
            $table->unsignedSmallInteger('orden');
            // Texto tal como se leyó ("LAM GALV C26 3.05"); null si el admin agregó el renglón a mano
            $table->string('descripcion_leida', 255)->nullable();
            // Concepto sugerido o elegido. Si se fusiona y se borra, queda null en lugar de
            // impedir la fusión: es solo una sugerencia hasta validar
            $table->foreignId('concepto_id')->nullable()->constrained('conceptos')->nullOnDelete();
            // Nombre de un concepto NUEVO que escribió el admin; se crea al validar
            $table->string('concepto_nombre', 200)->nullable();
            // Pueden quedar vacíos si la lectura no los encontró; se exigen al validar
            $table->decimal('cantidad', 10, 2)->nullable();
            $table->string('unidad_medida', 50)->nullable();
            $table->decimal('monto', 12, 2)->nullable();
            // El gasto que se creó con este renglón al validar
            $table->foreignId('gasto_id')->nullable()->constrained('gastos')->restrictOnDelete();
            $table->timestamps();

            $table->index(['ticket_id', 'orden']);
        });

        Schema::table('gastos', function (Blueprint $table) {
            // Ticket del que salió el gasto (null en captura manual). Solo el admin corrige estos gastos
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            // Texto leído del ticket, para rastrear de qué renglón salió
            $table->string('descripcion_ticket', 255)->nullable();
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->dropIndex(['ticket_id']);
            $table->dropConstrainedForeignId('ticket_id');
            $table->dropColumn('descripcion_ticket');
        });

        Schema::dropIfExists('ticket_renglones');
        Schema::dropIfExists('tickets');
    }
};
