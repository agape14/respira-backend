<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cita extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'citas';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = true;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'paciente_id',
        'medico_id',
        'turno_id',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'video_enlace',
        'user_id',
        'estado',
        'id_sesion'
    ];

    protected $casts = [
        'fecha' => 'date',
        'hora_inicio' => 'string',
        'hora_fin' => 'string',
        'estado' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Formato de fecha para serialización
     */
    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * Relaciones Eloquent
     */
    public function paciente()
    {
        return $this->belongsTo(Usuario::class, 'paciente_id');
    }

    public function medico()
    {
        return $this->belongsTo(Usuario::class, 'medico_id');
    }

    public function turno()
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    public function sesion()
    {
        return $this->belongsTo(SesionUno::class, 'id_sesion');
    }

    public function derivado()
    {
        return $this->hasOne(Derivado::class, 'cita_id');
    }

    public function finalizado()
    {
        return $this->hasOne(CitasFinalizado::class, 'cita_id');
    }
}
