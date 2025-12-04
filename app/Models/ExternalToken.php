<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalToken extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected $connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected $table = 'external_tokens';

    /**
     * Indica si el modelo usa timestamps
     */
    public $timestamps = false;

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'token',
        'nombre_aplicacion',
        'descripcion',
        'consejo_regional_id',
        'estado',
        'fecha_creacion',
        'fecha_expiracion',
        'ultimo_uso'
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos
     */
    protected $casts = [
        'estado' => 'integer',
        'consejo_regional_id' => 'integer',
    ];

    /**
     * Validar si el token es válido
     */
    public function esValido(): bool
    {
        // El token debe estar activo
        if ($this->estado != 1) {
            return false;
        }

        // Si tiene fecha de expiración, debe estar vigente
        if ($this->fecha_expiracion) {
            $ahora = now_lima();
            $expiracion = \Carbon\Carbon::parse($this->fecha_expiracion);

            if ($ahora->gt($expiracion)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Actualizar último uso
     */
    public function actualizarUso(): void
    {
        $this->ultimo_uso = now_lima();
        $this->save();
    }
}

