<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PerfilesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $perfiles = [
            [
                'nombre_perfil' => 'Administrador',
                'descripcion' => 'Acceso completo al sistema con todos los permisos',
                'permiso_ver' => 1,
                'permiso_editar' => 1,
                'permiso_eliminar' => 1,
                'estado' => 1,
            ],
            [
                'nombre_perfil' => 'Psicólogo',
                'descripcion' => 'Profesional de salud mental con acceso a gestión de pacientes y protocolos',
                'permiso_ver' => 1,
                'permiso_editar' => 1,
                'permiso_eliminar' => 0,
                'estado' => 1,
            ],
            [
                'nombre_perfil' => 'Coordinador',
                'descripcion' => 'Coordinador de área con permisos de gestión y supervisión',
                'permiso_ver' => 1,
                'permiso_editar' => 1,
                'permiso_eliminar' => 0,
                'estado' => 1,
            ],
            [
                'nombre_perfil' => 'Colaborador',
                'descripcion' => 'Usuario colaborador con permisos de lectura y edición limitada',
                'permiso_ver' => 1,
                'permiso_editar' => 1,
                'permiso_eliminar' => 0,
                'estado' => 1,
            ],
            [
                'nombre_perfil' => 'Visualizador',
                'descripcion' => 'Usuario con permisos de solo lectura',
                'permiso_ver' => 1,
                'permiso_editar' => 0,
                'permiso_eliminar' => 0,
                'estado' => 1,
            ],
        ];

        // Usar la conexión SQL Server
        DB::connection('sqlsrv')->table('perfiles')->insert($perfiles);

        $this->command->info('✓ Perfiles creados exitosamente');
    }
}

