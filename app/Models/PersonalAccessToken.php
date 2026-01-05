<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Illuminate\Support\Carbon;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Conexión a la base de datos SQL Server
     */
    protected $connection = 'sqlsrv';

    /**
     * Los atributos que deben ser convertidos a tipos nativos.
     * No usamos cast para expires_at para evitar problemas con null en SQL Server
     * Pero sí usamos cast para created_at y updated_at para que Laravel los maneje correctamente
     */
    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Formato de fecha para SQL Server DATETIME2
     * Usar formato ISO 8601 que SQL Server entiende mejor
     */
    protected $dateFormat = 'Y-m-d\TH:i:s';

    /**
     * Deshabilitar timestamps automáticos y manejarlos manualmente
     */
    public $timestamps = true;

    /**
     * Boot del modelo - registrar eventos
     */
    protected static function boot()
    {
        parent::boot();

        // Antes de crear, remover expires_at si es null y asegurar formato correcto para created_at/updated_at
        static::creating(function ($model) {
            // Si expires_at es null o inválido, removerlo completamente
            if (array_key_exists('expires_at', $model->attributes)) {
                $value = $model->attributes['expires_at'];
                if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
                    unset($model->attributes['expires_at']);
                }
            }

            // Asegurar que created_at y updated_at estén en el formato correcto para SQL Server
            // Usar objetos Carbon directamente para que PDO los maneje correctamente
            $now = now();

            // Establecer como objeto Carbon - PDO debería manejarlo correctamente
            $model->attributes['created_at'] = $now;
            $model->attributes['updated_at'] = $now;
        });
    }

    /**
     * Obtener los atributos que han cambiado
     */
    public function getDirty()
    {
        $dirty = parent::getDirty();

        // Asegurar que expires_at sea null si está presente
        if (array_key_exists('expires_at', $dirty)) {
            $value = $dirty['expires_at'];
            if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
                $dirty['expires_at'] = null;
            }
        }

        return $dirty;
    }

    /**
     * Obtener los atributos del modelo
     */
    public function getAttributes()
    {
        $attributes = parent::getAttributes();

        // Asegurar que expires_at sea null si está presente
        if (array_key_exists('expires_at', $attributes)) {
            $value = $attributes['expires_at'];
            if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
                $attributes['expires_at'] = null;
            }
        }

        return $attributes;
    }

    /**
     * Insertar el modelo y establecer el ID
     * Removemos expires_at si es null y aseguramos formato correcto para created_at/updated_at
     */
    protected function insertAndSetId(\Illuminate\Database\Eloquent\Builder $query, $attributes)
    {
        // Si expires_at es null o inválido, removerlo completamente del array
        if (array_key_exists('expires_at', $attributes)) {
            $value = $attributes['expires_at'];
            if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
                unset($attributes['expires_at']);
            }
        }

        // Asegurar que created_at y updated_at estén en el formato correcto para SQL Server
        // Usar formato ISO 8601 con 'T' que SQL Server entiende mejor
        foreach (['created_at', 'updated_at'] as $dateField) {
            if (array_key_exists($dateField, $attributes)) {
                $value = $attributes[$dateField];
                // Si es un objeto DateTime/Carbon, formatearlo como ISO 8601
                if ($value instanceof \DateTimeInterface) {
                    $attributes[$dateField] = $value->format('Y-m-d\TH:i:s');
                } elseif (is_string($value)) {
                    // Si ya es string, convertir a ISO 8601
                    try {
                        $date = Carbon::parse($value);
                        $attributes[$dateField] = $date->format('Y-m-d\TH:i:s');
                    } catch (\Exception $e) {
                        // Si no se puede parsear, usar ahora formateado
                        $attributes[$dateField] = now()->format('Y-m-d\TH:i:s');
                    }
                }
            }
        }

        return parent::insertAndSetId($query, $attributes);
    }

    /**
     * Preparar el modelo para la inserción
     * Removemos expires_at si es null y aseguramos formato correcto para created_at/updated_at
     */
    protected function performInsert(\Illuminate\Database\Eloquent\Builder $query)
    {
        // Remover expires_at de los atributos si es null o inválido
        if (array_key_exists('expires_at', $this->attributes)) {
            $value = $this->attributes['expires_at'];
            if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
                unset($this->attributes['expires_at']);
            }
        }

        // Asegurar que created_at y updated_at estén formateados correctamente en formato ISO 8601
        foreach (['created_at', 'updated_at'] as $dateField) {
            if (array_key_exists($dateField, $this->attributes)) {
                $value = $this->attributes[$dateField];
                // Formatear explícitamente como ISO 8601 con 'T'
                if ($value instanceof \DateTimeInterface) {
                    $this->attributes[$dateField] = $value->format('Y-m-d\TH:i:s');
                } elseif (is_string($value)) {
                    // Convertir a ISO 8601
                    try {
                        $date = Carbon::parse($value);
                        $this->attributes[$dateField] = $date->format('Y-m-d\TH:i:s');
                    } catch (\Exception $e) {
                        $this->attributes[$dateField] = now()->format('Y-m-d\TH:i:s');
                    }
                }
            }
        }

        return parent::performInsert($query);
    }

    /**
     * Mutator para expires_at - maneja correctamente los valores null
     */
    public function setExpiresAtAttribute($value)
    {
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
            $this->attributes['expires_at'] = null;
        } elseif ($value instanceof Carbon) {
            $this->attributes['expires_at'] = $value->format('Y-m-d H:i:s');
        } else {
            $this->attributes['expires_at'] = $value;
        }
    }

    /**
     * Accessor para expires_at - convierte a Carbon cuando no es null
     */
    public function getExpiresAtAttribute($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $this->asDateTime($value);
        } catch (\Exception $e) {
            return null;
        }
    }
}
