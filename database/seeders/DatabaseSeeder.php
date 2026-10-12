<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Administrador
        User::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Manuel Ceballos',
                'password' => Hash::make('colima123'),
                'rol' => 'Administrador',
                'estado' => 'Activo',
            ]
        );

        // Trabajador
        User::updateOrCreate(
            ['email' => 'ejemplo@gmail.com'],
            [
                'name' => 'Gael Ceballos',
                'password' => Hash::make('colima123'),
                'rol' => 'Trabajador',
                'estado' => 'Activo',
            ]
        );
    }
}