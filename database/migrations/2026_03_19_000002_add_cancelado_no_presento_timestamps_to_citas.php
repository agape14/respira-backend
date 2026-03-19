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
        Schema::connection('sqlsrv')->table('citas', function (Blueprint $table) {
            $table->dateTime('cancelado_at')->nullable()->after('estado_observacion');
            $table->dateTime('no_presento_at')->nullable()->after('cancelado_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sqlsrv')->table('citas', function (Blueprint $table) {
            $table->dropColumn('no_presento_at');
            $table->dropColumn('cancelado_at');
        });
    }
};

