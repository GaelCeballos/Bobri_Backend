<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prepara `gastos` para la captura real (manual y por QR) desde el teléfono.
 *
 * - `cantidad` opcional y `unidad_medida` propia: en mano de obra y servicios ("Pago a
 *   electricista") no hay cantidad ni unidad. Las dos van en pareja (si va una, va la otra).
 *   La unidad es la de ESTA compra; la del concepto solo es la sugerida.
 * - `url_xml`: el modelo ya la declaraba pero la columna no existía. La llenará la rama
 *   `feature/DescargaSAT`, igual que `url_pdf`.
 * - `rfc_emisor`: RFC del proveedor leído del QR; alimenta "Proveedores con más pagos".
 * - `uuid_cliente`: id que genera el teléfono al capturar. Si un envío sin señal se reintenta,
 *   el backend reconoce el gasto y no lo duplica.
 * - `actualizado_por` y soft delete con `eliminado_por`: decisión del dueño, en lugar de una
 *   bitácora. Un gasto borrado deja de contar pero el admin puede ver quién lo borró.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            // change() redefine toda la columna: se repiten tipo y precisión
            $table->decimal('cantidad', 10, 2)->nullable()->change();
            $table->string('unidad_medida', 50)->nullable()->after('cantidad');
            $table->string('url_xml', 500)->nullable()->after('url_pdf');
            // Persona moral: 12 caracteres; persona física: 13
            $table->string('rfc_emisor', 13)->nullable()->after('uuid_sat');
            $table->uuid('uuid_cliente')->nullable()->unique();

            // Los usuarios no se borran (se desactivan), por eso restrict
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('eliminado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->softDeletes();

            // Postgres NO crea índices para las llaves foráneas. Estos cubren los filtros de la
            // pantalla de gastos (obra, trabajador, rango de fechas) y "Lo más comprado"
            $table->index(['obra_id', 'fecha_gasto']);
            $table->index(['user_id', 'fecha_gasto']);
            $table->index('fecha_gasto');
            $table->index('concepto_id');
            $table->index('rfc_emisor');

            // Se reemplaza abajo por un índice único parcial
            $table->dropUnique(['uuid_sat']);
        });

        // La misma factura no se puede registrar dos veces... salvo que el gasto anterior se
        // haya borrado: si el trabajador borra un gasto mal capturado, debe poder volver a
        // capturar esa factura. Por eso el índice único ignora los gastos eliminados
        DB::statement('CREATE UNIQUE INDEX gastos_uuid_sat_unique ON gastos (uuid_sat) WHERE deleted_at IS NULL');

        // Reglas que la base de datos garantiza aunque algún código se salte la validación
        DB::statement('ALTER TABLE gastos ADD CONSTRAINT gastos_cantidad_unidad_en_pareja
            CHECK ((cantidad IS NULL) = (unidad_medida IS NULL))');
        DB::statement('ALTER TABLE gastos ADD CONSTRAINT gastos_monto_positivo CHECK (monto_total > 0)');
        DB::statement('ALTER TABLE gastos ADD CONSTRAINT gastos_cantidad_positiva CHECK (cantidad IS NULL OR cantidad > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE gastos DROP CONSTRAINT gastos_cantidad_positiva');
        DB::statement('ALTER TABLE gastos DROP CONSTRAINT gastos_monto_positivo');
        DB::statement('ALTER TABLE gastos DROP CONSTRAINT gastos_cantidad_unidad_en_pareja');
        DB::statement('DROP INDEX gastos_uuid_sat_unique');

        // Antes la cantidad era obligatoria: los pagos sin cantidad quedan como 1
        DB::table('gastos')->whereNull('cantidad')->update(['cantidad' => 1]);

        Schema::table('gastos', function (Blueprint $table) {
            $table->unique('uuid_sat');
            $table->dropIndex(['rfc_emisor']);
            $table->dropIndex(['concepto_id']);
            $table->dropIndex(['fecha_gasto']);
            $table->dropIndex(['user_id', 'fecha_gasto']);
            $table->dropIndex(['obra_id', 'fecha_gasto']);

            $table->dropSoftDeletes();
            $table->dropConstrainedForeignId('eliminado_por');
            $table->dropConstrainedForeignId('actualizado_por');
            $table->dropUnique(['uuid_cliente']);
            $table->dropColumn(['uuid_cliente', 'rfc_emisor', 'url_xml', 'unidad_medida']);
            $table->decimal('cantidad', 10, 2)->nullable(false)->change();
        });
    }
};
