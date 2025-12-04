<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens;

    /**
     * Get the class name for polymorphic relations.
     *
     * @return string
     */
    public function getMorphClass()
    {
        return 'App\Models\Usuario';
    }

    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'usuarios';

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
        'usuariomoodle',
        'idmoodle',
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
        'perfil_id',
        'estado',
        'sexo',
        'fecha_creacion',
        'fecha_modificacion',
        'user_asignado',
        'enrolacionauto'
    ];

    /**
     * Método requerido por Authenticatable para especificar la columna de contraseña
     */
    public function getAuthPassword()
    {
        return $this->contrasena;
    }

    /**
     * Aquí puedes definir tus relaciones Eloquent
     *
     * Ejemplo:
     * public function relacion()
     * {
     *     return $this->hasMany(OtroModelo::class);
     * }
     */

    /**
     * Relaciones con encuestas de riesgo
     */
    public function phq9Responses()
    {
        return $this->hasMany(Phq9Response::class, 'user_id', 'id');
    }

    public function gadResponses()
    {
        return $this->hasMany(GadResponse::class, 'user_id', 'id');
    }

    /**
     * Relación con turnos (shifts)
     */
    public function turnos()
    {
        return $this->hasMany(Turno::class, 'medico_id', 'id');
    }
}
