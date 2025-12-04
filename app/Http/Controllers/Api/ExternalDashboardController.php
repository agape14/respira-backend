<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExternalDashboardController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/external/dashboard",
     *     summary="Obtener datos del dashboard externo con token y filtrado por consejo regional",
     *     description="Endpoint público que requiere token de autenticación y parámetro id_cr (consejo regional). Retorna estadísticas filtradas por consejo regional.",
     *     tags={"Dashboard Externo"},
     *     @OA\Parameter(
     *         name="token",
     *         in="query",
     *         required=true,
     *         description="Token de autenticación para acceso externo",
     *         @OA\Schema(type="string", example="abc123xyz456")
     *     ),
     *     @OA\Parameter(
     *         name="id_cr",
     *         in="query",
     *         required=true,
     *         description="ID del consejo regional para filtrar datos",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Datos del dashboard obtenidos correctamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="consejo_regional_id", type="integer", example=1),
     *             @OA\Property(
     *                 property="estadisticas_generales",
     *                 type="object",
     *                 @OA\Property(property="total_serumistas", type="integer", example=150),
     *                 @OA\Property(property="evaluaciones_totales", type="integer", example=320),
     *                 @OA\Property(property="citas_atendidas", type="integer", example=25),
     *                 @OA\Property(property="protocolos_activos", type="integer", example=45)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Token inválido o expirado",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Token inválido o expirado")
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Token no tiene acceso a este consejo regional",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Este token no tiene acceso al consejo regional especificado")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validación de parámetros fallida",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Los parámetros token e id_cr son requeridos")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        try {
            // Validar parámetros requeridos
            $validator = Validator::make($request->all(), [
                'token' => 'required|string',
                'id_cr' => 'required|integer'
            ], [
                'token.required' => 'El token es requerido',
                'id_cr.required' => 'El ID del consejo regional es requerido',
                'id_cr.integer' => 'El ID del consejo regional debe ser un número entero'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $token = $request->input('token');
            $idCr = $request->input('id_cr');

            // Buscar y validar el token
            $tokenModel = ExternalToken::where('token', $token)->first();

            if (!$tokenModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido'
                ], 401);
            }

            if (!$tokenModel->esValido()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token expirado o inactivo'
                ], 401);
            }

            // Validar que el token tenga acceso a este consejo regional
            // Si consejo_regional_id es null, tiene acceso a todos
            if ($tokenModel->consejo_regional_id !== null && $tokenModel->consejo_regional_id != $idCr) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este token no tiene acceso al consejo regional especificado'
                ], 403);
            }

            // Actualizar último uso del token
            $tokenModel->actualizarUso();

            // Obtener datos del dashboard filtrados por consejo regional
            $dashboardData = $this->getDashboardDataByConsejoRegional($idCr);

            return response()->json([
                'success' => true,
                'consejo_regional_id' => $idCr,
                'token_info' => [
                    'nombre_aplicacion' => $tokenModel->nombre_aplicacion,
                    'ultimo_uso' => $tokenModel->ultimo_uso
                ],
                ...$dashboardData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener datos del dashboard',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * Obtener datos del dashboard filtrados por consejo regional
     */
    private function getDashboardDataByConsejoRegional($idCr)
    {
        // Obtener IDs de usuarios del consejo regional especificado
        $usuariosIds = DB::table('usuarios')
            ->where('consejo', $idCr)
            ->where('estado', 1)
            ->pluck('id');

        // Si no hay usuarios, retornar datos vacíos
        if ($usuariosIds->isEmpty()) {
            return $this->getEmptyDashboardData();
        }

        // Contar serumistas del consejo regional
        $totalSerumistas = DB::table('usuarios')
            ->where('consejo', $idCr)
            ->where('perfil_id', 4) // Perfil de serumista
            ->where('estado', 1)
            ->count();

        // Contar evaluaciones por tipo para usuarios del consejo regional
        $totalAsq = DB::table('asq5_responses')
            ->whereIn('user_id', $usuariosIds)
            ->count();

        $totalPhq = DB::table('phq9_responses')
            ->whereIn('user_id', $usuariosIds)
            ->count();

        $totalGad = DB::table('gad_responses')
            ->whereIn('user_id', $usuariosIds)
            ->count();

        $totalMbi = DB::table('mbi_responses')
            ->whereIn('user_id', $usuariosIds)
            ->count();

        $totalAudit = DB::table('audit_responses')
            ->whereIn('user_id', $usuariosIds)
            ->count();

        $totalEvaluaciones = $totalAsq + $totalPhq + $totalGad + $totalMbi + $totalAudit;

        // Contar citas de pacientes del consejo regional
        $citasAtendidas = DB::table('citas')
            ->whereIn('paciente_id', $usuariosIds)
            ->where('estado', 2) // Estado atendido
            ->count();

        // Contar protocolos activos del consejo regional
        $protocolosActivos = DB::table('curso_abordaje')
            ->where('Consejo Regional', $idCr)
            ->count();

        // Estadísticas Generales
        $estadisticas_generales = [
            'total_serumistas' => $totalSerumistas,
            'evaluaciones_totales' => $totalEvaluaciones,
            'citas_atendidas' => $citasAtendidas,
            'protocolos_activos' => $protocolosActivos,
        ];

        // Evaluaciones por Tipo
        $evaluaciones_por_tipo = [
            'asq' => [
                'total' => $totalAsq,
                'porcentaje' => $this->calcularPorcentaje($totalAsq, $totalEvaluaciones),
            ],
            'phq' => [
                'total' => $totalPhq,
                'porcentaje' => $this->calcularPorcentaje($totalPhq, $totalEvaluaciones),
            ],
            'gad' => [
                'total' => $totalGad,
                'porcentaje' => $this->calcularPorcentaje($totalGad, $totalEvaluaciones),
            ],
            'mbi' => [
                'total' => $totalMbi,
                'porcentaje' => $this->calcularPorcentaje($totalMbi, $totalEvaluaciones),
            ],
            'audit' => [
                'total' => $totalAudit,
                'porcentaje' => $this->calcularPorcentaje($totalAudit, $totalEvaluaciones),
            ],
        ];

        // Estado de Citas
        $estado_citas = [
            ['estado' => 'Agendado', 'cantidad' => DB::table('citas')->whereIn('paciente_id', $usuariosIds)->where('estado', 1)->count(), 'color' => '#3b82f6'],
            ['estado' => 'Atendido', 'cantidad' => DB::table('citas')->whereIn('paciente_id', $usuariosIds)->where('estado', 2)->count(), 'color' => '#10b981'],
            ['estado' => 'Reagendado', 'cantidad' => DB::table('citas')->whereIn('paciente_id', $usuariosIds)->whereIn('estado', [5])->count(), 'color' => '#f59e0b'],
            ['estado' => 'Cancelado', 'cantidad' => DB::table('citas')->whereIn('paciente_id', $usuariosIds)->where('estado', 4)->count(), 'color' => '#6b7280'],
            ['estado' => 'No se presentó', 'cantidad' => DB::table('citas')->whereIn('paciente_id', $usuariosIds)->where('estado', 3)->count(), 'color' => '#ef4444'],
        ];

        // Tendencia Mensual
        $tendencia_mensual = $this->getTendenciaMensualByConsejoRegional($usuariosIds);

        // Alertas específicas del consejo regional
        $alertas = $this->getAlertasByConsejoRegional($usuariosIds, $idCr);

        return [
            'estadisticas_generales' => $estadisticas_generales,
            'evaluaciones_por_tipo' => $evaluaciones_por_tipo,
            'estado_citas' => $estado_citas,
            'tendencia_mensual' => $tendencia_mensual,
            'alertas' => $alertas,
        ];
    }

    /**
     * Obtener datos vacíos para cuando no hay información
     */
    private function getEmptyDashboardData()
    {
        return [
            'estadisticas_generales' => [
                'total_serumistas' => 0,
                'evaluaciones_totales' => 0,
                'citas_atendidas' => 0,
                'protocolos_activos' => 0,
            ],
            'evaluaciones_por_tipo' => [
                'asq' => ['total' => 0, 'porcentaje' => 0],
                'phq' => ['total' => 0, 'porcentaje' => 0],
                'gad' => ['total' => 0, 'porcentaje' => 0],
                'mbi' => ['total' => 0, 'porcentaje' => 0],
                'audit' => ['total' => 0, 'porcentaje' => 0],
            ],
            'estado_citas' => [
                ['estado' => 'Agendado', 'cantidad' => 0, 'color' => '#3b82f6'],
                ['estado' => 'Atendido', 'cantidad' => 0, 'color' => '#10b981'],
                ['estado' => 'Reagendado', 'cantidad' => 0, 'color' => '#f59e0b'],
                ['estado' => 'Cancelado', 'cantidad' => 0, 'color' => '#6b7280'],
                ['estado' => 'No se presentó', 'cantidad' => 0, 'color' => '#ef4444'],
            ],
            'tendencia_mensual' => [],
            'alertas' => [
                [
                    'tipo' => 'info',
                    'mensaje' => 'No hay datos disponibles para este consejo regional'
                ]
            ],
        ];
    }

    /**
     * Obtener tendencia mensual filtrada por consejo regional
     */
    private function getTendenciaMensualByConsejoRegional($usuariosIds)
    {
        if ($usuariosIds->isEmpty()) {
            return [];
        }

        $meses = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = now_lima()->subMonths($i);
            $mesNombre = $date->locale('es')->format('M');
            $mesNum = $date->month;
            $year = $date->year;

            // Contar citas en este mes para este consejo regional
            $citas = DB::table('citas')
                ->whereIn('paciente_id', $usuariosIds)
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $mesNum)
                ->count();

            // Contar evaluaciones en este mes
            $evaluaciones = 0;
            $evaluaciones += DB::table('asq5_responses')->whereIn('user_id', $usuariosIds)->whereYear('fecha_registro', $year)->whereMonth('fecha_registro', $mesNum)->count();
            $evaluaciones += DB::table('phq9_responses')->whereIn('user_id', $usuariosIds)->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->count();
            $evaluaciones += DB::table('gad_responses')->whereIn('user_id', $usuariosIds)->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->count();
            $evaluaciones += DB::table('mbi_responses')->whereIn('user_id', $usuariosIds)->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->count();
            $evaluaciones += DB::table('audit_responses')->whereIn('user_id', $usuariosIds)->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->count();

            $meses[] = [
                'mes' => ucfirst($mesNombre),
                'Citas' => $citas,
                'Evaluaciones' => $evaluaciones
            ];
        }

        return $meses;
    }

    /**
     * Obtener alertas específicas del consejo regional
     */
    private function getAlertasByConsejoRegional($usuariosIds, $idCr)
    {
        if ($usuariosIds->isEmpty()) {
            return [];
        }

        $alertas = [];

        // ASQ con RSA positivo
        $rsaPositivo = DB::table('asq5_responses')
            ->whereIn('user_id', $usuariosIds)
            ->where('resultado', 'SI')
            ->count();

        if ($rsaPositivo > 0) {
            $alertas[] = [
                'tipo' => 'warning',
                'mensaje' => "{$rsaPositivo} serumistas con RSA positivo requieren seguimiento prioritario"
            ];
        }

        // PHQ con riesgo alto
        $phqAlto = DB::table('phq9_responses')
            ->whereIn('user_id', $usuariosIds)
            ->where('riesgo', 'Alto')
            ->count();

        if ($phqAlto > 0) {
            $alertas[] = [
                'tipo' => 'danger',
                'mensaje' => "{$phqAlto} casos de riesgo alto en PHQ requieren atención especializada"
            ];
        }

        // Citas sin presentarse
        $sinPresentarse = DB::table('citas')
            ->whereIn('paciente_id', $usuariosIds)
            ->where('estado', 3)
            ->where('fecha_programada', '>=', now_lima()->subMonth())
            ->count();

        if ($sinPresentarse > 0) {
            $alertas[] = [
                'tipo' => 'info',
                'mensaje' => "{$sinPresentarse} citas sin presentarse en el último mes - revisar casos"
            ];
        }

        // Si no hay alertas, agregar mensaje informativo
        if (empty($alertas)) {
            $alertas[] = [
                'tipo' => 'success',
                'mensaje' => 'No hay alertas críticas en este momento para este consejo regional'
            ];
        }

        return $alertas;
    }

    /**
     * Calcular porcentaje
     */
    private function calcularPorcentaje($valor, $total)
    {
        return $total > 0 ? round(($valor / $total) * 100) : 0;
    }
}

