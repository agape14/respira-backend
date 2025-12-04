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
        Schema::connection('sqlsrv')->table('derivados', function (Blueprint $table) {
            // Agregar columna tipo: A = Automático (por riesgo alto), M = Manual (desde atención)
            $table->char('tipo', 1)->default('A')->after('cenate_id');

            // Agregar índice para mejorar rendimiento en consultas
            $table->index('tipo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sqlsrv')->table('derivados', function (Blueprint $table) {
            $table->dropIndex(['tipo']);
            $table->dropColumn('tipo');
        });
    }
};

