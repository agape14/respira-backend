<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Actualiza los registros existentes en derivados:
     * - Si NO tienen cita_id → tipo = 'A' (Automático por riesgo alto)
     * - Si tienen cita_id → tipo = 'M' (Manual desde atención)
     */
    public function up(): void
    {
        // Actualizar derivaciones sin cita_id (automáticas por riesgo alto)
        DB::connection('sqlsrv')->table('derivados')
            ->whereNull('cita_id')
            ->update(['tipo' => 'A']);

        // Actualizar derivaciones con cita_id (manuales desde atención)
        DB::connection('sqlsrv')->table('derivados')
            ->whereNotNull('cita_id')
            ->update(['tipo' => 'M']);

        // Si hay registros con tipo NULL, asignarles 'A' por defecto
        DB::connection('sqlsrv')->table('derivados')
            ->whereNull('tipo')
            ->update(['tipo' => 'A']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No revertir, mantener los tipos asignados
    }
};

