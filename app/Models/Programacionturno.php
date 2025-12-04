<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Programacionturno extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'programacionturno';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = true;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'medico_id',
        'fecha_inicio',
        'fecha_fin',
        'tiempo_sesion',
        'dias',
        'horario'
    ];

    /**
     * Campos que deben ser parseados como JSON
     */
    protected $casts = [
        'dias' => 'array',
        'horario' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relaciones Eloquent
     */
    public function medico()
    {
        return $this->belongsTo(Usuario::class, 'medico_id');
    }
}
