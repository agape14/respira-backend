<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TextoConsentimiento extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'textoConsentimiento';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = false;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'texto',
        'fecha'
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
