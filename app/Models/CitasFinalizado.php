<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CitasFinalizado extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'citas_finalizados';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = false;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'cita_id',
        'paciente_id',
        'observa',
        'fecha',
        'user_id'
    ];

    /**
     * Conversión de tipos de datos
     */
    protected $casts = [
        'fecha' => 'datetime',
    ];

    /**
     * Relaciones Eloquent
     */
    public function cita()
    {
        return $this->belongsTo(Cita::class, 'cita_id');
    }

    public function paciente()
    {
        return $this->belongsTo(Usuario::class, 'paciente_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
