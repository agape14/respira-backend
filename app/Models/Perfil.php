<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Perfil extends Model
{
    protected $table = 'perfiles';
    public $timestamps = false;

    protected $fillable = [
        'nombre_perfil',
        'descripcion',
        'permiso_ver',
        'permiso_editar',
        'permiso_eliminar',
        'estado',
    ];

    protected $casts = [
        'permiso_ver' => 'boolean',
        'permiso_editar' => 'boolean',
        'permiso_eliminar' => 'boolean',
        'estado' => 'boolean',
    ];

    /**
     * Relación con usuarios
     */
    public function usuarios()
    {
        return $this->hasMany(User::class, 'perfil_id');
    }
}

