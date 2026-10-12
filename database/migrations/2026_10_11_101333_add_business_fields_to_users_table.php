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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('rol',['Administrador', 'Trabajador'])
                    ->default('Trabajador')
                    ->after('email');
            
            $table->enum('estado', ['Activo', 'Inactivo'])
                    ->default('Activo')
                    ->after('rol');
            
            $table->text('current_token')
                ->nullable()
                ->after('estado');
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['rol', 'estado', 'current_token']);
        });
    }
};
