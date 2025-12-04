<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MbiResponse extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'mbi_responses';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = false;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'id_encuesta',
        'fecha',
        'puntajeCE',
        'puntajeDP',
        'puntajeRP',
        'riesgoCE',
        'riesgoDP',
        'riesgoRP',
        'respuestas',
        'user_id'
    ];

    /**
     * Aquí puedes definir tus relaciones Eloquent
     *
     * Ejemplo:
     * public function relacion()
     * {
     *     return $this->hasMany(OtroModelo::class);
     * }
     */
}
