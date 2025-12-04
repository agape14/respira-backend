<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *     title="Respira-CMP API",
 *     version="1.0.0",
 *     description="API del Sistema de Gestión de Evaluaciones Psicológicas para Serumistas",
 *     @OA\Contact(
 *         email="soporte@colegiomedicoperú.com"
 *     )
 * )
 *
 * @OA\Server(
 *     url="http://localhost:8000",
 *     description="Servidor Local de Desarrollo"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Token de autenticación Sanctum"
 * )
 */
abstract class Controller
{
    //
}
