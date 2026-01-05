<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * Conexión a la base de datos SQL Server
     */
    protected $connection = 'sqlsrv';

    /**
     * Disable automatic password rehashing to prevent SQL errors with custom column names.
     */
    protected $rehashPasswordOnLogin = false;

    protected $table = 'usuarios';
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
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
        'enrolacionauto',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'contrasena',
    ];

    /**
     * No usar casting de 'hashed' para mantener compatibilidad con bcrypt de CodeIgniter
     * Laravel verificará las contraseñas bcrypt existentes correctamente con Hash::check()
     */
    protected $casts = [
        'estado' => 'boolean',
        'enrolacionauto' => 'boolean',
        'fecha_creacion' => 'datetime',
        'fecha_modificacion' => 'datetime',
    ];

    /**
     * Override password field name para usar 'contrasena' en lugar de 'password'
     */
    public function getAuthPassword()
    {
        return $this->contrasena;
    }

    /**
     * Get the name of the password attribute for the user.
     *
     * @return string
     */
    public function getAuthPasswordName()
    {
        return 'contrasena';
    }

    /**
     * Override username field para usar 'nombre_usuario' en lugar de 'email'
     */
    public function getAuthIdentifierName()
    {
        return 'nombre_usuario';
    }

    /**
     * Relación con perfil
     */
    public function perfil()
    {
        return $this->belongsTo(Perfil::class, 'perfil_id');
    }

    /**
     * Scope para usuarios activos
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', 1);
    }

    /**
     * Verificar si el usuario tiene un perfil específico
     */
    public function tienePerfil($perfilId)
    {
        return $this->perfil_id == $perfilId;
    }

    /**
     * Verificar si el usuario es admin
     */
    public function esAdmin()
    {
        return $this->perfil_id == 1;
    }
    public function asq5Responses()
    {
        return $this->hasMany(Asq5Response::class, 'user_id');
    }

    public function phq9Responses()
    {
        return $this->hasMany(Phq9Response::class, 'user_id');
    }

    public function gadResponses()
    {
        return $this->hasMany(GadResponse::class, 'user_id');
    }

    public function mbiResponses()
    {
        return $this->hasMany(MbiResponse::class, 'user_id');
    }

    public function auditResponses()
    {
        return $this->hasMany(AuditResponse::class, 'user_id');
    }
}

