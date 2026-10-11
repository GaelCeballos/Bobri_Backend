<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('conceptos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre',200)->unique();
            $table->enum('categoria', [
                'Materiales',
                'Mano de Obra',
                'Equipo/Herramienta',
                'Combustible',
                'Varios'
            ]);
            $table->string('unidad_medida', 50)->default('pieza');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conceptos');
    }
};
