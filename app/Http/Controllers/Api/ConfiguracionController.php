<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TextoConsentimiento;
use App\Models\ExternalToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class ConfiguracionController extends Controller
{
    /**
     * Obtener el documento de conformidad desde la tabla textoConsentimiento
     */
    public function getConformidad()
    {
        try {
            // Obtener el primer registro de la tabla textoConsentimiento
            $textoConsentimiento = TextoConsentimiento::first();

            if ($textoConsentimiento) {
                // Retornar el campo 'texto' como 'content' para mantener compatibilidad con el frontend
                return response()->json(['content' => $textoConsentimiento->texto]);
            }

            return response()->json(['content' => '']);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Error al obtener conformidad: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Guardar el documento de conformidad en la tabla textoConsentimiento
     */
    public function saveConformidad(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
        ]);

        try {
            // Buscar si ya existe un registro
            $textoConsentimiento = TextoConsentimiento::first();

            if ($textoConsentimiento) {
                // Actualizar el registro existente
                $textoConsentimiento->update([
                    'texto' => $request->content,
                    'fecha' => now_lima(),
                ]);
            } else {
                // Crear un nuevo registro
                TextoConsentimiento::create([
                    'texto' => $request->content,
                    'fecha' => now_lima(),
                ]);
            }

            return response()->json(['message' => 'Documento guardado correctamente']);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Error al guardar conformidad: ' . $e->getMessage()], 500);
        }
    }

    /**
     * ========================================================================
     * GESTIÓN DE TOKENS EXTERNOS
     * ========================================================================
     */

    /**
     * Listar todos los tokens externos
     */
    public function getTokens()
    {
        try {
            $tokens = ExternalToken::orderBy('fecha_creacion', 'desc')->get();

            // Formatear datos para el frontend
            $tokensFormateados = $tokens->map(function($token) {
                return [
                    'id' => $token->id,
                    'token' => $token->token,
                    'nombre_aplicacion' => $token->nombre_aplicacion,
                    'descripcion' => $token->descripcion,
                    'consejo_regional_id' => $token->consejo_regional_id,
                    'consejo_regional_nombre' => $this->getNombreConsejoRegional($token->consejo_regional_id),
                    'estado' => (int)$token->estado, // Convertir explícitamente a entero
                    'estado_texto' => $token->estado == 1 ? 'Activo' : 'Inactivo',
                    'fecha_creacion' => $token->fecha_creacion ? \Carbon\Carbon::parse($token->fecha_creacion)->format('Y-m-d H:i:s') : null,
                    'fecha_expiracion' => $token->fecha_expiracion ? \Carbon\Carbon::parse($token->fecha_expiracion)->format('Y-m-d H:i:s') : null,
                    'ultimo_uso' => $token->ultimo_uso ? \Carbon\Carbon::parse($token->ultimo_uso)->format('Y-m-d H:i:s') : null,
                    'dias_hasta_expiracion' => $this->getDiasHastaExpiracion($token->fecha_expiracion),
                    'esta_expirado' => $this->estaExpirado($token->fecha_expiracion),
                ];
            });

            return response()->json($tokensFormateados);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al obtener tokens',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener consejos regionales disponibles
     */
    public function getConsejosRegionales()
    {
        try {
            // Obtener consejos regionales únicos de la tabla usuarios
            $consejos = DB::table('usuarios')
                ->select('consejo')
                ->distinct()
                ->whereNotNull('consejo')
                ->where('consejo', '!=', 0)
                ->orderBy('consejo')
                ->get()
                ->map(function($item) {
                    return [
                        'id' => $item->consejo,
                        'nombre' => 'Consejo Regional ' . $item->consejo
                    ];
                });

            return response()->json($consejos);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al obtener consejos regionales',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo token externo
     */
    public function createToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre_aplicacion' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:500',
            'consejo_regional_id' => 'nullable|integer',
            'duracion_dias' => 'required|integer|min:1|max:3650',
        ], [
            'nombre_aplicacion.required' => 'El nombre de la aplicación es requerido',
            'duracion_dias.required' => 'La duración en días es requerida',
            'duracion_dias.min' => 'La duración mínima es 1 día',
            'duracion_dias.max' => 'La duración máxima es 10 años',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $now = now_lima();
            $fechaExpiracion = now_lima()->addDays($request->duracion_dias);

            $token = ExternalToken::create([
                'token' => Str::random(40),
                'nombre_aplicacion' => $request->nombre_aplicacion,
                'descripcion' => $request->descripcion,
                'consejo_regional_id' => $request->consejo_regional_id,
                'estado' => 1,
                'fecha_creacion' => $now,
                'fecha_expiracion' => $fechaExpiracion,
                'ultimo_uso' => null
            ]);

            return response()->json([
                'message' => 'Token creado exitosamente',
                'token' => [
                    'id' => $token->id,
                    'token' => $token->token,
                    'nombre_aplicacion' => $token->nombre_aplicacion,
                    'descripcion' => $token->descripcion,
                    'consejo_regional_id' => $token->consejo_regional_id,
                    'estado' => $token->estado,
                    'fecha_creacion' => $token->fecha_creacion,
                    'fecha_expiracion' => $token->fecha_expiracion,
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al crear token',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un token existente
     */
    public function updateToken(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'nombre_aplicacion' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:500',
            'consejo_regional_id' => 'nullable|integer',
            'estado' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $token = ExternalToken::findOrFail($id);

            $token->update([
                'nombre_aplicacion' => $request->nombre_aplicacion,
                'descripcion' => $request->descripcion,
                'consejo_regional_id' => $request->consejo_regional_id,
                'estado' => $request->estado,
            ]);

            return response()->json([
                'message' => 'Token actualizado exitosamente',
                'token' => $token
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar token',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Renovar fecha de expiración de un token
     */
    public function renovarToken(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'duracion_dias' => 'required|integer|min:1|max:3650',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $token = ExternalToken::findOrFail($id);

            $nuevaFechaExpiracion = now_lima()->addDays($request->duracion_dias);

            $token->update([
                'fecha_expiracion' => $nuevaFechaExpiracion,
                'estado' => 1 // Reactivar el token al renovar
            ]);

            return response()->json([
                'message' => 'Token renovado exitosamente',
                'token' => $token
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al renovar token',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar (desactivar) un token
     */
    public function deleteToken($id)
    {
        try {
            $token = ExternalToken::findOrFail($id);

            // En lugar de eliminar, desactivamos el token
            $token->update(['estado' => 0]);

            return response()->json([
                'message' => 'Token desactivado exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al desactivar token',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ========================================================================
     * MÉTODOS AUXILIARES PRIVADOS
     * ========================================================================
     */

    private function getNombreConsejoRegional($consejoId)
    {
        if ($consejoId === null) {
            return 'Todos los Consejos';
        }

        return 'Consejo Regional ' . $consejoId;
    }

    private function getDiasHastaExpiracion($fechaExpiracion)
    {
        if (!$fechaExpiracion) {
            return null;
        }

        $ahora = now_lima();
        $expiracion = \Carbon\Carbon::parse($fechaExpiracion);

        return $ahora->diffInDays($expiracion, false);
    }

    private function estaExpirado($fechaExpiracion)
    {
        if (!$fechaExpiracion) {
            return false;
        }

        $ahora = now_lima();
        $expiracion = \Carbon\Carbon::parse($fechaExpiracion);

        return $ahora->gt($expiracion);
    }
}
