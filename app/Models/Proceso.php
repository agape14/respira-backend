<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proceso extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'procesos';
    protected $primaryKey = 'id_proceso';
    public $incrementing = true;
    protected $keyType = 'int';

    // La tabla tiene created_at/updated_at, pero updated_at puede ser null
    public $timestamps = true;

    protected $fillable = [
        'anio',
        'corte',
        'etiqueta',
        'fecha_inicio',
        'fecha_fin',
        'activo',
    ];
}


