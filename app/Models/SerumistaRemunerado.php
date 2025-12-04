<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SerumistaRemunerado extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'serumista_remunerados';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = false;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'PROFESION',
        'SEDE DE ADJUDICACIÓN',
        'MODALIDAD',
        'DIRESA_GERESA_DIRIS',
        'INSTITUCION',
        'DEPARTAMENTO',
        'PROVINCIA',
        'DISTRITO',
        'GRADO DE DIFICULTAD',
        'CODIGO_RENIPRESS_MODULAR',
        'NOMBRE DE ESTABLECIMIENTO',
        'CATEGORIA',
        'PRESUPUESTO',
        'ZAF (*)',
        'ZE (**)',
        'APELLIDOS Y NOMBRES',
        'NumeroDocumento',
        'CMP',
        'Email',
        'F20',
        'F21',
        'F22',
        'F23',
        'F24',
        'F25',
        'F26',
        'F27',
        'F28',
        'F29'
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
    public function usuario()
    {
        return $this->belongsTo(User::class, 'CMP', 'cmp');
    }
}
