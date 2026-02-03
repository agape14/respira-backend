<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Almacena los checks seleccionados de la pregunta 9 (factores desencadenantes)
     * como JSON array de claves. disparadores_existe sigue guardando el texto "Otros".
     */
    public function up(): void
    {
        Schema::connection('sqlsrv')->table('sesionUno', function (Blueprint $table) {
            $table->json('disparadores_check')->nullable()->after('disparadores_existe');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sqlsrv')->table('sesionUno', function (Blueprint $table) {
            $table->dropColumn('disparadores_check');
        });
    }
};
