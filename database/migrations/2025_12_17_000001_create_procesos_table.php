<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sqlsrv')->create('procesos', function (Blueprint $table) {
            $table->bigIncrements('id_proceso');
            $table->smallInteger('anio');
            $table->char('corte', 2); // I | II
            $table->string('etiqueta', 20)->unique(); // 2025-I
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->boolean('activo')->default(true);
            $table->dateTime('created_at')->default(DB::raw('GETDATE()'));
            $table->dateTime('updated_at')->nullable();

            $table->unique(['anio', 'corte']);
            $table->index(['activo', 'anio']);
        });

        // Seed por defecto (según requerimiento)
        $exists = DB::connection('sqlsrv')->table('procesos')->count();
        if ($exists === 0) {
            DB::connection('sqlsrv')->table('procesos')->insert([
                [
                    'anio' => 2025,
                    'corte' => 'I',
                    'etiqueta' => '2025-I',
                    'fecha_inicio' => '2025-01-01',
                    'fecha_fin' => '2025-06-30',
                    'activo' => 1,
                ],
                [
                    'anio' => 2025,
                    'corte' => 'II',
                    'etiqueta' => '2025-II',
                    'fecha_inicio' => '2025-07-01',
                    'fecha_fin' => '2025-12-31',
                    'activo' => 1,
                ],
                [
                    'anio' => 2026,
                    'corte' => 'I',
                    'etiqueta' => '2026-I',
                    'fecha_inicio' => '2026-01-01',
                    'fecha_fin' => '2026-06-30',
                    'activo' => 1,
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('procesos');
    }
};


