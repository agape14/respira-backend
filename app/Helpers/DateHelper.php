<?php

namespace App\Helpers;

use Carbon\Carbon;

class DateHelper
{
    /**
     * Obtiene la fecha y hora actual en la zona horaria de Lima, Perú
     * Formato compatible con SQL Server datetime2
     *
     * @return Carbon
     */
    public static function nowLima(): Carbon
    {
        return Carbon::now('America/Lima');
    }

    /**
     * Obtiene la fecha y hora actual en formato para SQL Server
     * Formato: Y-m-d H:i:s (compatible con datetime2)
     *
     * @return string
     */
    public static function nowLimaFormatted(): string
    {
        return self::nowLima()->format('Y-m-d H:i:s');
    }

    /**
     * Obtiene solo la fecha actual en la zona horaria de Lima
     * Formato: Y-m-d
     *
     * @return string
     */
    public static function todayLima(): string
    {
        return self::nowLima()->format('Y-m-d');
    }

    /**
     * Convierte una fecha/hora a la zona horaria de Lima
     *
     * @param mixed $datetime
     * @return Carbon
     */
    public static function toLima($datetime): Carbon
    {
        if ($datetime instanceof Carbon) {
            return $datetime->setTimezone('America/Lima');
        }

        return Carbon::parse($datetime, 'America/Lima');
    }

    /**
     * Convierte una fecha/hora a la zona horaria de Lima en formato SQL Server
     *
     * @param mixed $datetime
     * @return string
     */
    public static function toLimaFormatted($datetime): string
    {
        return self::toLima($datetime)->format('Y-m-d H:i:s');
    }
}

