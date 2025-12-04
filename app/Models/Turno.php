<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turno extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'turnos';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = false;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'medico_id',
        'dia',
        'hora_inicio',
        'hora_fin',
        'fecha',
        'user_id'
    ];

    /**
     * Conversión de tipos de datos
     */
    protected $casts = [
        'fecha' => 'date',
    ];

    /**
     * Relaciones Eloquent
     */
    public function medico()
    {
        return $this->belongsTo(Usuario::class, 'medico_id');
    }

    public function cita()
    {
        return $this->hasOne(Cita::class, 'turno_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
