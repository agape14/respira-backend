<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/login",
     *     summary="Iniciar sesión",
     *     tags={"Autenticación"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"nombre_usuario", "password"},
     *             @OA\Property(property="nombre_usuario", type="string", example="admin"),
     *             @OA\Property(property="password", type="string", example="admin123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login exitoso",
     *         @OA\JsonContent(
     *             @OA\Property(property="access_token", type="string"),
     *             @OA\Property(property="token_type", type="string", example="Bearer"),
     *             @OA\Property(property="user", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Credenciales incorrectas"
     *     )
     * )
     */
    public function login(Request $request)
    {
        $request->validate([
            'nombre_usuario' => 'required|string',
            'password' => 'required|string',
        ]);

        // Buscar usuario por nombre_usuario con perfil
        $user = User::with('perfil')
            ->where('nombre_usuario', $request->nombre_usuario)
            ->where('estado', 1)
            ->first();

        // Verificar si el usuario existe y la contraseña es correcta
        if (!$user || !Hash::check($request->password, $user->contrasena)) {
            throw ValidationException::withMessages([
                'nombre_usuario' => ['Nombre de usuario o contraseña incorrectos'],
            ]);
        }

        // Crear token sin expires_at para evitar problemas con SQL Server
        // Laravel Sanctum manejará expires_at automáticamente si es null
        $token = $user->createToken('auth_token', ['*'], null)->plainTextToken;

        // Preparar datos del usuario con perfil
        $userData = [
            'id' => $user->id,
            'nombre_usuario' => $user->nombre_usuario,
            'nombre_completo' => $user->nombre_completo,
            'perfil_id' => $user->perfil_id,
            'perfil' => [
                'id' => $user->perfil->id,
                'nombre_perfil' => $user->perfil->nombre_perfil,
                'permiso_ver' => $user->perfil->permiso_ver,
                'permiso_editar' => $user->perfil->permiso_editar,
                'permiso_eliminar' => $user->perfil->permiso_eliminar,
            ],
            'cmp' => $user->cmp,
        ];

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $userData,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/logout",
     *     summary="Cerrar sesión",
     *     tags={"Autenticación"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Sesión cerrada correctamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Sesión cerrada correctamente")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/user",
     *     summary="Obtener usuario autenticado",
     *     tags={"Autenticación"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Usuario autenticado con perfil",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="nombre_usuario", type="string", example="fuyu"),
     *             @OA\Property(property="nombre_completo", type="string", example="Fuyu Collantes"),
     *             @OA\Property(property="perfil", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     */
    public function getAuthenticatedUser(Request $request)
    {
        return response()->json($request->user()->load('perfil'));
    }

    /**
     * Obtener el usuario autenticado con perfil (método legacy)
     */
    public function user(Request $request)
    {
        $user = $request->user()->load('perfil');

        return response()->json([
            'id' => $user->id,
            'nombre_usuario' => $user->nombre_usuario,
            'nombre_completo' => $user->nombre_completo,
            'perfil_id' => $user->perfil_id,
            'perfil' => [
                'id' => $user->perfil->id,
                'nombre_perfil' => $user->perfil->nombre_perfil,
                'permiso_ver' => $user->perfil->permiso_ver,
                'permiso_editar' => $user->perfil->permiso_editar,
                'permiso_eliminar' => $user->perfil->permiso_eliminar,
            ],
            'cmp' => $user->cmp,
        ]);
    }
}

