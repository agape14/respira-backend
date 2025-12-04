<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsuariosUpload extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'usuarios_upload';

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
        'estado',
        'sexo',
        'fecha_creacion',
        'perfil_id',
        'usuariomoodle',
        'idmoodle'
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
