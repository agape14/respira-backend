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
        Schema::connection('sqlsrv')->create('derivaciones_atencion', function (Blueprint $table) {
            $table->id();
            $table->integer('paciente_id'); // FK a usuarios
            $table->date('fecha_atencion'); // Fecha en que la entidad atendió al paciente
            $table->char('tipo_derivacion', 1)->default('A'); // A=Automática, M=Manual
            $table->string('entidad', 100)->nullable(); // ESSALUD, MINSA, etc.
            $table->text('observacion')->nullable();
            $table->datetime('fecha_registro')->default(DB::raw('GETDATE()'));

            $table->foreign('paciente_id')->references('id')->on('usuarios')->onDelete('cascade');
            $table->index('paciente_id');
            $table->index('tipo_derivacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('derivaciones_atencion');
    }
};

