<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsuariosSinEnrolar extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'usuarios_sin_enrolar';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = false;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'cmp',
        'nombre_usuario',
        'contrasena',
        'nombre_completo',
        'nombre',
        'apellido',
        'telefono',
        'direccion',
        'departamento',
        'consejo',
        'contesto',
        'enrolo',
        'estado',
        'sexo',
        'user_asignado',
        'user_id',
        'fecha_creacion',
        'usuariomoodle',
        'idmoodle',
        'perfil_id'
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
