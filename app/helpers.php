<?php

use App\Helpers\DateHelper;
use Carbon\Carbon;

if (!function_exists('now_lima')) {
    /**
     * Obtiene la fecha y hora actual en la zona horaria de Lima, Perú
     *
     * @return Carbon
     */
    function now_lima(): Carbon
    {
        return DateHelper::nowLima();
    }
}

if (!function_exists('now_lima_formatted')) {
    /**
     * Obtiene la fecha y hora actual formateada para SQL Server (Lima, Perú)
     *
     * @return string
     */
    function now_lima_formatted(): string
    {
        return DateHelper::nowLimaFormatted();
    }
}

if (!function_exists('today_lima')) {
    /**
     * Obtiene solo la fecha actual en la zona horaria de Lima
     *
     * @return string
     */
    function today_lima(): string
    {
        return DateHelper::todayLima();
    }
}

