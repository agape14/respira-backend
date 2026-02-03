<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SesionUno extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'sesionUno';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = false;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'donde_vives',
        'con_quien',
        'tiempo_en_casa',
        'bien_en_casa',
        'relaciones_afectuosas',
        'comodo_en_trabajo',
        'estres_en_trabajo',
        'estudiando',
        'preocupaciones',
        'usa_productos',
        'ejercicio_regular',
        'comes_duermes_bien',
        'que_haces_divertirte',
        'que_haces_relajarte',
        'conectarte_comunidad',
        'problema_motiva',
        'tiempo_empezo',
        'tiempo_notado',
        'tiempo_problema',
        'disparadores_existe',
        'disparadores_check',
        'trayectoria_problema',
        'trayectoria_habido',
        'trayectoria_reciente',
        'severidad_grande',
        'intentos_solucion',
        'costes_funcion',
        'costes_plazo',
        'costes_problema',
        'costes_pensando',
        'apertura',
        'consciencia',
        'hacer_importa',
        'establecimiento',
        'intervencion',
        'recomendacionsesionuno',
        'sesiondos_revision',
        'sesiondos_intervencion',
        'sesiondos_progreso',
        'recomendacionsesiondos',
        'sesiontres_revision',
        'sesiontres_intervencion',
        'sesiontres_progreso',
        'recomendacionsesiontres',
        'sesioncuatro_revision',
        'sesioncuatro_intervencion',
        'sesioncuatro_progreso',
        'recomendacionsesioncuatro',
        'fecha_inicio',
        'nro_sesion',
        'paciente_id',
        'user_id'
    ];

    /**
     * Conversión de tipos de datos
     */
    protected $casts = [
        'fecha_inicio' => 'datetime',
        'disparadores_check' => 'array',
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
