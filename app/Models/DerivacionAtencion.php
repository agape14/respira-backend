<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DerivacionAtencion extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'derivaciones_atencion';
    public $timestamps = false;

    protected $fillable = [
        'paciente_id',
        'fecha_atencion',
        'tipo_derivacion',
        'entidad',
        'observacion',
        'fecha_registro'
    ];

    protected $casts = [
        'fecha_atencion' => 'date',
        'fecha_registro' => 'datetime',
    ];

    public function paciente()
    {
        return $this->belongsTo(User::class, 'paciente_id');
    }
}

