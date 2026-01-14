<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CitaController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DashboardDerivacionesController;
use App\Http\Controllers\Api\ExternalDashboardController;
use App\Http\Controllers\Api\ProcesoController;
use App\Http\Controllers\Api\ResultadoTamizajeController;
use App\Http\Controllers\Api\ProtocoloAtencionController;
use App\Http\Controllers\Api\DerivacionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PerfilController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\ConfiguracionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

// Rutas públicas de autenticación
Route::post('/login', [AuthController::class, 'login']);

// DEBUG (solo local): permite probar el DashboardController sin auth para diagnosticar errores de queries
Route::get('/debug/dashboard-data', function (Request $request, DashboardController $controller) {
    abort_unless(app()->environment('local'), 404);
    return $controller->index($request);
});

Route::get('/debug/derivados-sample', function () {
    abort_unless(app()->environment('local'), 404);
    $count = DB::table('derivados')->count();
    $sample = DB::table('derivados')->select('id', 'paciente_id', 'cita_id', 'tipo', 'fecha')->orderByDesc('id')->limit(10)->get();
    return response()->json([
        'count' => $count,
        'sample' => $sample,
    ]);
});

Route::get('/debug/derivaciones-atencion-sample', function () {
    abort_unless(app()->environment('local'), 404);
    $count = DB::table('derivaciones_atencion')->count();
    $sample = DB::table('derivaciones_atencion')->select('id', 'paciente_id', 'entidad', 'tipo_derivacion', 'fecha_atencion', 'fecha_registro')->orderByDesc('id')->limit(10)->get();
    return response()->json([
        'count' => $count,
        'sample' => $sample,
    ]);
});

Route::get('/debug/serumistas-counts', function () {
    abort_unless(app()->environment('local'), 404);

    $remCount = DB::table('serumista_remunerados')->count();
    $eqCount = DB::table('serumista_equivalentes_remunerados')->count();

    $remModalidades = DB::table('serumista_remunerados')
        ->selectRaw('TOP 20 MODALIDAD')
        ->whereNotNull('MODALIDAD')
        ->groupBy('MODALIDAD')
        ->get()
        ->pluck('MODALIDAD');

    $eqModalidades = DB::table('serumista_equivalentes_remunerados')
        ->selectRaw('TOP 20 MODALIDAD')
        ->whereNotNull('MODALIDAD')
        ->groupBy('MODALIDAD')
        ->get()
        ->pluck('MODALIDAD');

    return response()->json([
        'serumista_remunerados' => [
            'count' => $remCount,
            'modalidades' => $remModalidades,
        ],
        'serumista_equivalentes_remunerados' => [
            'count' => $eqCount,
            'modalidades' => $eqModalidades,
        ],
    ]);
});

Route::get('/debug/describe/{table}', function (string $table) {
    abort_unless(app()->environment('local'), 404);
    $cols = DB::select("
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
    ", [$table]);
    return response()->json($cols);
});

// DEBUG: Verificar token actual
Route::get('/debug/verify-token-61', function() {
    $token = DB::table('personal_access_tokens')->find(61);

    if (!$token) {
        return response()->json(['error' => 'Token 61 no existe']);
    }

    $fullToken = '61|L11tWZctv9Q8XqNTT415VgNbDUL5YiDMDl4IVY06df79e471';
    $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($fullToken);

    $authModel = config('auth.providers.users.model');

    $user = App\Models\Usuario::find(1);
    $morphClass = $user->getMorphClass();

    return response()->json([
        'token_61_db' => [
            'id' => $token->id,
            'tokenable_type' => $token->tokenable_type,
            'tokenable_id' => $token->tokenable_id,
        ],
        'sanctum_found' => $tokenModel ? 'SI' : 'NO',
        'sanctum_tokenable_type' => $tokenModel ? $tokenModel->tokenable_type : null,
        'auth_config_model' => $authModel,
        'usuario_morph_class' => $morphClass,
        'match' => $token->tokenable_type === $authModel ? 'SI' : 'NO',
    ]);
});

// Ruta de debug SIMPLE para verificar que el token se envía correctamente
Route::get('/debug/token-check', function(Request $request) {
    $bearerToken = $request->bearerToken();

    \Log::info('=== DEBUG TOKEN CHECK ===', [
        'has_bearer' => $bearerToken ? 'SI' : 'NO',
        'bearer_preview' => $bearerToken ? substr($bearerToken, 0, 20) . '...' : null,
        'auth_header' => $request->header('Authorization'),
    ]);

    if (!$bearerToken) {
        return response()->json([
            'error' => 'No se recibió token',
            'headers' => $request->headers->all(),
        ], 400);
    }

    // Intentar encontrar el token
    $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);

    if (!$tokenModel) {
        return response()->json([
            'error' => 'Token no encontrado en base de datos',
            'token_preview' => substr($bearerToken, 0, 20) . '...',
        ], 401);
    }

    $user = $tokenModel->tokenable;

    return response()->json([
        'success' => true,
        'token_valid' => true,
        'user_id' => $user->id,
        'user_name' => $user->nombre_usuario,
        'token_created' => $tokenModel->created_at,
        'token_expires' => $tokenModel->expires_at,
    ]);
});

// Ruta de debug con autenticación
Route::get('/debug/auth-test', function(Request $request) {
    return response()->json([
        'message' => 'Autenticado correctamente',
        'user' => $request->user(),
    ]);
})->middleware('auth:sanctum');

// Ruta pública para dashboard externo (requiere token y id_cr en query params)
Route::get('/external/dashboard', [ExternalDashboardController::class, 'index']);

// Rutas de debug (temporal - sin autenticación para diagnóstico rápido)
Route::get('/debug/check-usuarios', function() {
    try {
        $usuarios = DB::connection('sqlsrv')->select("SELECT TOP 3 * FROM usuarios WHERE perfil_id = 4 AND estado = 1");
        return response()->json([
            'total' => count($usuarios),
            'sample' => $usuarios,
            'columnas' => $usuarios ? array_keys((array)$usuarios[0]) : []
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::get('/debug/check-serumistas', function() {
    try {
        $serumistas = DB::connection('sqlsrv')->select("SELECT TOP 3 * FROM serumista_remunerados");
        return response()->json([
            'total' => count($serumistas),
            'sample' => $serumistas,
            'columnas' => $serumistas ? array_keys((array)$serumistas[0]) : []
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::get('/debug/check-join', function() {
    try {
        $result = DB::connection('sqlsrv')->select("
            SELECT TOP 5
                COALESCE(u.id, 0) AS id,
                s.NumeroDocumento AS dni,
                s.[APELLIDOS Y NOMBRES] AS nombre_completo,
                s.CMP AS cmp,
                s.Email as email,
                u.nombre_usuario,

                -- Probar una subquery de evaluación
                (SELECT TOP 1 resultado FROM asq5_responses WHERE user_id = u.id ORDER BY id DESC) AS asq_resultado,
                (SELECT TOP 1 riesgo FROM phq9_responses WHERE user_id = u.id ORDER BY id DESC) AS phq_riesgo

            FROM serumista_remunerados s
            LEFT JOIN usuarios u ON LOWER(s.Email) = LOWER(u.nombre_usuario) AND u.perfil_id = 4 AND u.estado = 1
        ");
        return response()->json(['total' => count($result), 'data' => $result]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage(), 'line' => $e->getLine()], 500);
    }
});

Route::get('/debug/check-evaluaciones', function() {
    try {
        // Verificar si existen datos en las tablas de evaluaciones
        $asq_count = DB::connection('sqlsrv')->select("SELECT COUNT(*) as total FROM asq5_responses");
        $phq_count = DB::connection('sqlsrv')->select("SELECT COUNT(*) as total FROM phq9_responses");
        $gad_count = DB::connection('sqlsrv')->select("SELECT COUNT(*) as total FROM gad_responses");
        $mbi_count = DB::connection('sqlsrv')->select("SELECT COUNT(*) as total FROM mbi_responses");
        $audit_count = DB::connection('sqlsrv')->select("SELECT COUNT(*) as total FROM audit_responses");

        // Obtener una muestra de cada tabla
        $asq_sample = DB::connection('sqlsrv')->select("SELECT TOP 3 * FROM asq5_responses ORDER BY id DESC");
        $phq_sample = DB::connection('sqlsrv')->select("SELECT TOP 3 * FROM phq9_responses ORDER BY id_encuesta DESC");
        $gad_sample = DB::connection('sqlsrv')->select("SELECT TOP 3 * FROM gad_responses ORDER BY id_encuesta DESC");
        $mbi_sample = DB::connection('sqlsrv')->select("SELECT TOP 3 * FROM mbi_responses ORDER BY id_encuesta DESC");
        $audit_sample = DB::connection('sqlsrv')->select("SELECT TOP 3 * FROM audit_responses ORDER BY id_encuesta DESC");

        return response()->json([
            'totales' => [
                'asq5' => $asq_count[0]->total,
                'phq9' => $phq_count[0]->total,
                'gad' => $gad_count[0]->total,
                'mbi' => $mbi_count[0]->total,
                'audit' => $audit_count[0]->total
            ],
            'muestras' => [
                'asq5' => $asq_sample,
                'phq9' => $phq_sample,
                'gad' => $gad_sample,
                'mbi' => $mbi_sample,
                'audit' => $audit_sample
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::get('/debug/check-usuarios-con-evaluaciones', function() {
    try {
        // Verificar los usuarios con evaluaciones y si están vinculados a serumistas
        $usuarios_con_eval = DB::connection('sqlsrv')->select("
            SELECT TOP 10
                u.id,
                u.nombre_completo,
                u.nombre_usuario,
                u.cmp,
                u.perfil_id,
                u.estado,
                s.NumeroDocumento,
                s.CMP as serumista_cmp,
                s.Email as serumista_email,
                s.[APELLIDOS Y NOMBRES] as serumista_nombre,

                -- Contar evaluaciones
                (SELECT COUNT(*) FROM asq5_responses WHERE user_id = u.id) as tiene_asq,
                (SELECT COUNT(*) FROM phq9_responses WHERE user_id = u.id) as tiene_phq,
                (SELECT COUNT(*) FROM gad_responses WHERE user_id = u.id) as tiene_gad,
                (SELECT COUNT(*) FROM mbi_responses WHERE user_id = u.id) as tiene_mbi,
                (SELECT COUNT(*) FROM audit_responses WHERE user_id = u.id) as tiene_audit

            FROM usuarios u
            LEFT JOIN serumista_remunerados s ON LOWER(s.Email) = LOWER(u.nombre_usuario)
            WHERE u.id IN (573, 572, 515, 195, 196, 197)
            ORDER BY u.id DESC
        ");

        return response()->json([
            'total' => count($usuarios_con_eval),
            'usuarios' => $usuarios_con_eval
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::get('/debug/test-relacion-cmp', function() {
    try {
        // Probar relación por CMP en lugar de email
        $result = DB::connection('sqlsrv')->select("
            SELECT TOP 5
                s.CMP,
                s.[APELLIDOS Y NOMBRES] as serumista_nombre,
                s.Email as serumista_email,
                u.id as usuario_id,
                u.cmp as usuario_cmp,
                u.nombre_usuario,
                u.nombre_completo as usuario_nombre,
                u.perfil_id,
                u.estado,

                -- Test evaluaciones con el usuario encontrado
                (SELECT COUNT(*) FROM asq5_responses WHERE user_id = u.id) as tiene_asq,
                (SELECT COUNT(*) FROM phq9_responses WHERE user_id = u.id) as tiene_phq

            FROM serumista_remunerados s
            LEFT JOIN usuarios u ON s.CMP = u.cmp
            WHERE u.id IS NOT NULL
        ");

        return response()->json([
            'total_con_usuario_vinculado' => count($result),
            'data' => $result,
            'mensaje' => count($result) > 0
                ? 'Se encontraron serumistas vinculados por CMP'
                : 'No hay serumistas vinculados por CMP. Probar otra relación.'
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::get('/debug/test-serumista-con-evaluaciones', function() {
    try {
        // Buscar serumistas con CMPs que sabemos tienen evaluaciones
        // Del debug anterior: 111703, 104443, 104162, 102727, 103868, 103970, 104762
        $result = DB::connection('sqlsrv')->select("
            SELECT
                COALESCE(u.id, 0) AS id,
                s.NumeroDocumento AS dni,
                s.[APELLIDOS Y NOMBRES] AS nombre_completo,
                s.CMP AS cmp,
                u.nombre_completo as usuario_nombre,
                u.cmp as usuario_cmp,
                u.estado,

                -- ASQ5 - Últimos datos
                (SELECT TOP 1 resultado FROM asq5_responses WHERE user_id = u.id ORDER BY id DESC) AS asq,
                (SELECT TOP 1 fecha_registro FROM asq5_responses WHERE user_id = u.id ORDER BY id DESC) AS asq_fecha,

                -- PHQ9 - Últimos datos
                (SELECT TOP 1 riesgo FROM phq9_responses WHERE user_id = u.id ORDER BY id DESC) AS phq,
                (SELECT TOP 1 fecha FROM phq9_responses WHERE user_id = u.id ORDER BY id DESC) AS phq_fecha,

                -- GAD - Últimos datos
                (SELECT TOP 1 riesgo FROM gad_responses WHERE user_id = u.id ORDER BY id DESC) AS gad,

                -- MBI - Últimos datos
                (SELECT TOP 1 CONCAT(riesgoCE, '-', riesgoDP, '-', riesgoRP) FROM mbi_responses WHERE user_id = u.id ORDER BY id DESC) AS mbi,

                -- AUDIT - Últimos datos
                (SELECT TOP 1 riesgo FROM audit_responses WHERE user_id = u.id ORDER BY id DESC) AS audit

            FROM serumista_remunerados s
            LEFT JOIN usuarios u ON s.CMP = u.cmp AND u.estado = 1
            WHERE s.CMP IN ('111703', '104443', '104162', '102727', '103868', '103970', '104762')
        ");

        return response()->json([
            'total' => count($result),
            'data' => $result,
            'mensaje' => 'Estos son serumistas con CMPs que tienen evaluaciones registradas'
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

// Rutas protegidas
Route::middleware(['auth:sanctum'])->group(function () {
    // Autenticación
    Route::get('/user', [AuthController::class, 'getAuthenticatedUser']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Dashboard
    Route::get('/dashboard-data', [DashboardController::class, 'index']);
    Route::get('/dashboard-filtros', [DashboardController::class, 'filtros']);
    
    // Dashboard Derivaciones (endpoints separados para carga progresiva)
    Route::get('/dashboard-derivaciones/total-casos', [DashboardDerivacionesController::class, 'totalCasosDerivados']);
    Route::get('/dashboard-derivaciones/total-derivaciones', [DashboardDerivacionesController::class, 'totalDerivaciones']);
    Route::get('/dashboard-derivaciones/derivaciones-tamizaje', [DashboardDerivacionesController::class, 'derivacionesTamizaje']);

    // Tamizaje
    Route::get('/tamizajes/exportar-todo', [ResultadoTamizajeController::class, 'exportarTodo']);
    Route::get('/tamizajes/exportar/{dni}', [ResultadoTamizajeController::class, 'exportarIndividual']);
    Route::get('/tamizajes', [ResultadoTamizajeController::class, 'index']);
    Route::get('/tamizajes/{id}', [ResultadoTamizajeController::class, 'show']);

    // Citas y Turnos
    Route::get('/terapeutas', [CitaController::class, 'terapeutas']);
    Route::get('/citas/riesgo-moderado', [CitaController::class, 'indexRiesgoModerado']);
    Route::get('/pacientes/riesgo-moderado', [CitaController::class, 'pacientesRiesgoModerado']);
    Route::get('/citas', [CitaController::class, 'index']);
    Route::post('/turnos/programar', [CitaController::class, 'programar']);
    Route::get('/turnos', [CitaController::class, 'turnos']);
    Route::get('/turnos/calendario', [CitaController::class, 'calendario']);
    Route::get('/turnos/contar-disponibles', [CitaController::class, 'contarDisponibles']);
    Route::delete('/turnos/eliminar-todos', [CitaController::class, 'eliminarTodos']);
    Route::delete('/turnos/{id}', [CitaController::class, 'destroy']);
    Route::get('/citas', [CitaController::class, 'citas']);
    Route::post('/citas/agendar', [CitaController::class, 'agendar']);
    Route::get('/citas/intervencion-sesion/{paciente_id}', [CitaController::class, 'obtenerIntervencionSesion']);

    // Protocolos de Atención
    Route::get('/protocolos/stats', [ProtocoloAtencionController::class, 'stats']);
    Route::get('/protocolos', [ProtocoloAtencionController::class, 'index']);
    Route::post('/protocolos/agendar', [ProtocoloAtencionController::class, 'agendar']);
    Route::post('/protocolos/reprogramar', [ProtocoloAtencionController::class, 'reprogramar']);

    // Rutas específicas antes de la ruta genérica {id}
    Route::get('/protocolos/especialistas-derivacion', [ProtocoloAtencionController::class, 'getEspecialistasDerivacion']);
    Route::get('/protocolos/derivacion/{cita_id}', [ProtocoloAtencionController::class, 'getDerivacion']);
    Route::post('/protocolos/derivar', [ProtocoloAtencionController::class, 'derivar']);

    Route::get('/protocolos/{id}', [ProtocoloAtencionController::class, 'show']);
    Route::post('/protocolos/save', [ProtocoloAtencionController::class, 'save']);
    Route::post('/protocolos/finalizar_intervencion', [ProtocoloAtencionController::class, 'finalizarIntervencion']);
    Route::get('/protocolos/pdf/{paciente_id}', [ProtocoloAtencionController::class, 'generarPdf']);
    Route::post('/protocolos/cancelar', [ProtocoloAtencionController::class, 'cancelar']);
    Route::post('/protocolos/no_presento', [ProtocoloAtencionController::class, 'noPresento']);

    // Derivaciones
    Route::get('/derivaciones/stats', [DerivacionController::class, 'stats']);
    Route::get('/derivaciones', [DerivacionController::class, 'index']);
    Route::post('/derivaciones', [DerivacionController::class, 'store']);
    Route::get('/derivaciones/export', [DerivacionController::class, 'export']);

    // Gestión de Usuarios
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::get('/roles', [UserController::class, 'getRoles']);

    // Gestión de Perfiles
    Route::get('/perfiles', [PerfilController::class, 'index']);
    Route::post('/perfiles', [PerfilController::class, 'store']);
    Route::get('/perfiles/{id}', [PerfilController::class, 'show']);
    Route::put('/perfiles/{id}', [PerfilController::class, 'update']);
    Route::delete('/perfiles/{id}', [PerfilController::class, 'destroy']);
    Route::get('/perfiles/{id}/permisos', [PerfilController::class, 'getPermisos']);
    Route::post('/perfiles/{id}/permisos', [PerfilController::class, 'updatePermisos']);

    // Menús
    Route::get('/menus', [MenuController::class, 'index']);
    Route::get('/menus/by-profile', [MenuController::class, 'getMenusByProfile']);

    // Configuración (Documento de Conformidad)
    Route::get('/configuracion/conformidad', [ConfiguracionController::class, 'getConformidad']);
    Route::post('/configuracion/conformidad', [ConfiguracionController::class, 'saveConformidad']);

    // Configuración (Tokens Externos)
    Route::get('/configuracion/tokens', [ConfiguracionController::class, 'getTokens']);
    Route::get('/configuracion/consejos-regionales', [ConfiguracionController::class, 'getConsejosRegionales']);
    Route::post('/configuracion/tokens', [ConfiguracionController::class, 'createToken']);
    Route::put('/configuracion/tokens/{id}', [ConfiguracionController::class, 'updateToken']);
    Route::post('/configuracion/tokens/{id}/renovar', [ConfiguracionController::class, 'renovarToken']);
    Route::delete('/configuracion/tokens/{id}', [ConfiguracionController::class, 'deleteToken']);

    // Configuración (Cortes/Procesos)
    Route::get('/configuracion/procesos', [ProcesoController::class, 'index']);
    Route::post('/configuracion/procesos', [ProcesoController::class, 'store']);
    Route::put('/configuracion/procesos/{id}', [ProcesoController::class, 'update']);
    Route::delete('/configuracion/procesos/{id}', [ProcesoController::class, 'destroy']);

    // Debug routes
    Route::get('/debug/check-data', function() {
        $usuarios = DB::connection('sqlsrv')->select("SELECT TOP 5 id, cmp, nombre_completo, perfil_id, estado FROM usuarios WHERE perfil_id = 4 AND estado = 1");

        $result = [];
        foreach ($usuarios as $usuario) {
            $asq = DB::connection('sqlsrv')->select("SELECT TOP 1 resultado FROM asq5_responses WHERE user_id = ? ORDER BY id DESC", [$usuario->id]);
            $phq = DB::connection('sqlsrv')->select("SELECT TOP 1 riesgo FROM phq9_responses WHERE user_id = ? ORDER BY id DESC", [$usuario->id]);
            $gad = DB::connection('sqlsrv')->select("SELECT TOP 1 riesgo FROM gad_responses WHERE user_id = ? ORDER BY id DESC", [$usuario->id]);
            $mbi = DB::connection('sqlsrv')->select("SELECT TOP 1 riesgoCE FROM mbi_responses WHERE user_id = ? ORDER BY id DESC", [$usuario->id]);
            $audit = DB::connection('sqlsrv')->select("SELECT TOP 1 riesgo FROM audit_responses WHERE user_id = ? ORDER BY id DESC", [$usuario->id]);

            $result[] = [
                'usuario' => $usuario,
                'tiene_asq' => !empty($asq),
                'tiene_phq' => !empty($phq),
                'tiene_gad' => !empty($gad),
                'tiene_mbi' => !empty($mbi),
                'tiene_audit' => !empty($audit),
                'asq_valor' => !empty($asq) ? $asq[0]->resultado : null,
                'phq_valor' => !empty($phq) ? $phq[0]->riesgo : null,
            ];
        }

        return response()->json([
            'total_usuarios_perfil_4' => count($usuarios),
            'detalles' => $result
        ]);
    });
});

Route::get('/debug/storage-path', function() {
    return response()->json(['path' => storage_path('app/private')]);
});

Route::get('/test-teams-link', [CitaController::class, 'testTeamsLink']);
Route::get('/test-email', [CitaController::class, 'testEmail']);
