<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExternalTokenSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now_lima();
        $oneYearFromNow = now_lima()->addYear();

        // Verificar si ya existen tokens, si es así, solo mostrar mensaje
        $existingTokens = DB::connection('sqlsrv')->table('external_tokens')->count();

        if ($existingTokens > 0) {
            $this->command->warn('⚠️  Ya existen tokens en la base de datos');
            $this->command->info('Total de tokens: ' . $existingTokens);
            $this->command->info('Para ver los tokens, ejecuta: php ver_tokens.php');
            $this->command->newLine();
            $this->command->info('Si deseas crear nuevos tokens:');
            $this->command->info('  php generate_external_token.php --nombre="Mi Aplicación"');
            return;
        }

        // Token de ejemplo 1 - Acceso general a todos los consejos regionales
        DB::connection('sqlsrv')->table('external_tokens')->insert([
            'token' => Str::random(40),
            'nombre_aplicacion' => 'Aplicación Externa Demo',
            'descripcion' => 'Token de ejemplo para aplicación externa con acceso a todos los consejos regionales',
            'consejo_regional_id' => null, // null = acceso a todos
            'estado' => 1,
            'fecha_creacion' => $now,
            'fecha_expiracion' => $oneYearFromNow,
            'ultimo_uso' => null
        ]);

        // Token de ejemplo 2 - Acceso específico a un consejo regional
        DB::connection('sqlsrv')->table('external_tokens')->insert([
            'token' => Str::random(40),
            'nombre_aplicacion' => 'Sistema Regional Lima',
            'descripcion' => 'Token para acceso específico al consejo regional de Lima (ID: 1)',
            'consejo_regional_id' => 1,
            'estado' => 1,
            'fecha_creacion' => $now,
            'fecha_expiracion' => $oneYearFromNow,
            'ultimo_uso' => null
        ]);

        // Token de ejemplo 3 - Token simple para pruebas
        DB::connection('sqlsrv')->table('external_tokens')->insert([
            'token' => 'test_token_123456789',
            'nombre_aplicacion' => 'Token de Pruebas',
            'descripcion' => 'Token simple para pruebas de desarrollo - Solo para ambiente local',
            'consejo_regional_id' => null,
            'estado' => 1,
            'fecha_creacion' => $now,
            'fecha_expiracion' => $oneYearFromNow,
            'ultimo_uso' => null
        ]);

        $this->command->info('✓ Tokens externos creados exitosamente');
        $this->command->info('Para ver los tokens generados, ejecuta: php ver_tokens.php');
    }
}

