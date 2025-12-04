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
        Schema::connection('sqlsrv')->create('external_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 100)->unique();
            $table->string('nombre_aplicacion', 255);
            $table->string('descripcion', 500)->nullable();
            $table->integer('consejo_regional_id')->nullable()->comment('ID del consejo regional asociado, null para todos');
            $table->tinyInteger('estado')->default(1)->comment('1=activo, 0=inactivo');
            $table->datetime('fecha_creacion')->nullable();
            $table->datetime('fecha_expiracion')->nullable();
            $table->datetime('ultimo_uso')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('external_tokens');
    }
};

