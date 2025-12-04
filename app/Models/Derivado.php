<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Derivado extends Model
{
    protected $connection = 'sqlsrv';
    protected $table = 'derivados';
    public $timestamps = false; // Based on columns, no created_at/updated_at, just 'fecha'

    protected $fillable = [
        'cita_id',
        'cenate_id',
        'paciente_id',
        'observa',
        'fecha',
        'tipo'
    ];

    /**
     * Conversión de tipos de datos
     */
    protected $casts = [
        'fecha' => 'datetime',
    ];

    public function cita()
    {
        return $this->belongsTo(Cita::class, 'cita_id');
    }

    public function especialista()
    {
        return $this->belongsTo(Usuario::class, 'cenate_id');
    }

    public function paciente()
    {
        return $this->belongsTo(Usuario::class, 'paciente_id');
    }
}
