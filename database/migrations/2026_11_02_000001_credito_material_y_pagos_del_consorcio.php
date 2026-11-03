<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tres cambios de cómo se mueve la plata:
 *
 * 1. El material se le compra a la cantera A CRÉDITO: el vale confirma la
 *    compra y se le va abonando. La cuenta de material ya no nace en saldo
 *    cero: lleva abonos como cualquier otra.
 *
 * 2. A veces el conductor consigue o compra el material por su cuenta. Ese
 *    viaje no se le debe a la cantera sino a él: se le reconoce en su cuenta
 *    de pago (material_por_conductor).
 *
 * 3. Los pagos del consorcio: el anticipo al iniciar la obra llega antes de
 *    que exista cuenta de cobro. Un anticipo puede ser del consorcio sin
 *    cuenta, y aplicarse a una después. Lleva el número del comprobante de
 *    ingreso y la foto o PDF del comprobante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viajes', function (Blueprint $table) {
            $table->boolean('material_por_conductor')->default(false)->after('valor_material_m3');
        });

        Schema::table('anticipos', function (Blueprint $table) {
            $table->foreignId('consorcio_id')->nullable()->after('liquidacion_id')->constrained('consorcios')->restrictOnDelete();
            $table->string('numero_comprobante', 40)->nullable()->after('valor');
            $table->index('consorcio_id');
        });

        DB::statement('ALTER TABLE anticipos ALTER COLUMN liquidacion_id DROP NOT NULL');

        // Los anticipos que ya había en cuentas de cobro son pagos de ese consorcio.
        DB::statement("
            UPDATE anticipos SET consorcio_id = liquidaciones.consorcio_id
            FROM liquidaciones
            WHERE anticipos.liquidacion_id = liquidaciones.id AND liquidaciones.tipo = 'cobro'
        ");

        DB::statement("
            ALTER TABLE anticipos
            ADD CONSTRAINT anticipos_con_destino
            CHECK (liquidacion_id IS NOT NULL OR consorcio_id IS NOT NULL)
        ");

        // El archivo va aparte para que listar anticipos no arrastre megas.
        // En base64 dentro de Postgres: el disco de Railway se borra en cada
        // despliegue, y un comprobante pesa poco.
        Schema::create('comprobantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anticipo_id')->unique()->constrained('anticipos')->cascadeOnDelete();
            $table->string('nombre', 180);
            $table->string('mime', 80);
            $table->integer('tamano');
            $table->longText('contenido');
            $table->timestamps();
        });

        // Las cuentas de material que ya existen pasan a deber lo que suman.
        DB::statement("UPDATE liquidaciones SET saldo = total_bruto - total_anticipos WHERE tipo = 'material'");
    }

    public function down(): void
    {
        DB::statement("UPDATE liquidaciones SET saldo = 0 WHERE tipo = 'material'");

        Schema::dropIfExists('comprobantes');

        DB::statement('ALTER TABLE anticipos DROP CONSTRAINT anticipos_con_destino');
        DB::statement('DELETE FROM anticipos WHERE liquidacion_id IS NULL');
        DB::statement('ALTER TABLE anticipos ALTER COLUMN liquidacion_id SET NOT NULL');

        Schema::table('anticipos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consorcio_id');
            $table->dropColumn('numero_comprobante');
        });

        Schema::table('viajes', function (Blueprint $table) {
            $table->dropColumn('material_por_conductor');
        });
    }
};
