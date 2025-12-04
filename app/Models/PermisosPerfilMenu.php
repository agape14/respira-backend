<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermisosPerfilMenu extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'permisos_perfil_menu';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = false;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'perfil_id',
        'menu_id',
        'permiso_ver',
        'permiso_editar',
        'permiso_eliminar'
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
