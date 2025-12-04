<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/dashboard-data",
     *     summary="Obtener datos del dashboard",
     *     description="Retorna todas las estadísticas del dashboard: serumistas, evaluaciones, citas, protocolos, alertas",
     *     tags={"Dashboard"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Datos del dashboard obtenidos correctamente",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="estadisticas_generales",
     *                 type="object",
     *                 @OA\Property(property="total_serumistas", type="integer", example=2170),
     *                 @OA\Property(property="evaluaciones_totales", type="integer", example=2144),
     *                 @OA\Property(property="citas_atendidas", type="integer", example=33),
     *                 @OA\Property(property="protocolos_activos", type="integer", example=1889)
     *             ),
     *             @OA\Property(
     *                 property="evaluaciones_por_tipo",
     *                 type="object",
     *                 @OA\Property(
     *                     property="asq",
     *                     type="object",
     *                     @OA\Property(property="total", type="integer", example=671),
     *                     @OA\Property(property="porcentaje", type="integer", example=31)
     *                 )
     *             ),
     *             @OA\Property(property="estado_citas", type="object"),
     *             @OA\Property(property="alertas", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Error del servidor"
     *     )
     * )
     */
    public function index(Request $request)
    {
        try {
            // Contar registros directamente desde las tablas
            $totalSerumistas = DB::table('serumista_remunerados')->count();
            $totalAsq = DB::table('asq5_responses')->count();
            $totalPhq = DB::table('phq9_responses')->count();
            $totalGad = DB::table('gad_responses')->count();
            $totalMbi = DB::table('mbi_responses')->count();
            $totalAudit = DB::table('audit_responses')->count();
            $totalEvaluaciones = $totalAsq + $totalPhq + $totalGad + $totalMbi + $totalAudit;
            $totalCitas = DB::table('citas')->count();

            // Estadísticas Generales
            $estadisticas_generales = [
                'total_serumistas' => $totalSerumistas,
                'evaluaciones_totales' => $totalEvaluaciones,
                'citas_atendidas' => DB::table('citas')->where('estado', 2)->count(), // Asumiendo 2 es atendido
                'protocolos_activos' => DB::table('curso_abordaje')->count(),
            ];

            // Evaluaciones por Tipo
            $evaluaciones_por_tipo = [
                'asq' => [
                    'total' => $totalAsq,
                    'porcentaje' => $this->calcularPorcentaje($totalAsq, $totalEvaluaciones), // Porcentaje del total de evaluaciones
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

            // Distribución de Riesgos PHQ/GAD (simulado por ahora, idealmente calcular basado en puntajes)
            $distribucion_phq_gad = [
                [ 'nivel' => 'Riesgo alto', 'GAD' => 18, 'PHQ' => 22 ],
                [ 'nivel' => 'Riesgo Moderado', 'GAD' => 32, 'PHQ' => 38 ],
                [ 'nivel' => 'Riesgo leve', 'GAD' => 45, 'PHQ' => 42 ],
                [ 'nivel' => 'Sin riesgo', 'GAD' => 23, 'PHQ' => 16 ]
            ];

            // Estado de Citas (Formato para gráfico)
            $estado_citas = [
                ['estado' => 'Agendado', 'cantidad' => DB::table('citas')->where('estado', 1)->count(), 'color' => '#3b82f6'],
                ['estado' => 'Atendido', 'cantidad' => DB::table('citas')->where('estado', 2)->count(), 'color' => '#10b981'],
                ['estado' => 'Reagendado', 'cantidad' => DB::table('citas')->whereIn('estado', [5])->count(), 'color' => '#f59e0b'], // Asumiendo 5 es reagendado si existe, o ajustar
                ['estado' => 'Cancelado', 'cantidad' => DB::table('citas')->where('estado', 4)->count(), 'color' => '#6b7280'],
                ['estado' => 'No se presentó', 'cantidad' => DB::table('citas')->where('estado', 3)->count(), 'color' => '#ef4444'],
            ];

            // Tendencia Mensual (Real)
            $tendencia_mensual = $this->getTendenciaMensual();

            // Protocolos de Atención (Simulado - requiere lógica compleja de progreso)
            $protocolos = [
                'en_curso' => 45,
                'completados' => 78,
                'pendientes' => 23,
                'total_intervenciones' => 146,
                'promedio_sesiones_paciente' => 4.2,
            ];

            // Disponibilidad Horaria (Simulado - requiere lógica de horarios)
            $disponibilidad = [
                'horarios_disponibles' => 240,
                'horarios_ocupados' => 185,
                'terapeutas_activos' => 8,
                'tasa_ocupacion' => 77,
            ];

            // Alertas y Recomendaciones (Simulado)
            $alertas = [
                [
                    'tipo' => 'warning',
                    'mensaje' => '45 serumistas con RSA positivo requieren seguimiento prioritario',
                ],
                [
                    'tipo' => 'danger',
                    'mensaje' => '28 casos de riesgo alto en PHQ pendientes de atención especializada',
                ],
                [
                    'tipo' => 'info',
                    'mensaje' => '23 citas sin presentarse en el último mes - revisar casos',
                ],
                [
                    'tipo' => 'danger',
                    'mensaje' => '18 serumistas con consumo problemático (MBI) necesitan intervención inmediata',
                ],
            ];

            return response()->json([
                'estadisticas_generales' => $estadisticas_generales,
                'evaluaciones_por_tipo' => $evaluaciones_por_tipo,
                'distribucion_phq_gad' => $distribucion_phq_gad,
                'estado_citas' => $estado_citas,
                'tendencia_mensual' => $tendencia_mensual,
                'protocolos' => $protocolos,
                'disponibilidad' => $disponibilidad,
                'alertas' => $alertas,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al obtener datos del dashboard',
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ], 500);
        }
    }

    private function getTendenciaMensual()
    {
        $meses = [];
        // Últimos 6 meses
        for ($i = 5; $i >= 0; $i--) {
            $date = now_lima()->subMonths($i);
            $mesNombre = $date->format('M'); // Ene, Feb, etc. (En inglés por defecto, se puede localizar)
            $mesNum = $date->month;
            $year = $date->year;

            // Contar citas en este mes
            $citas = DB::table('citas')
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $mesNum)
                ->count();

            // Contar evaluaciones en este mes (sumando todas)
            $evaluaciones = 0;
            $evaluaciones += DB::table('asq5_responses')->whereYear('fecha_registro', $year)->whereMonth('fecha_registro', $mesNum)->count();
            $evaluaciones += DB::table('phq9_responses')->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->count();
            $evaluaciones += DB::table('gad_responses')->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->count();
            $evaluaciones += DB::table('mbi_responses')->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->count();
            $evaluaciones += DB::table('audit_responses')->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->count();

            $meses[] = [
                'mes' => $mesNombre,
                'Citas' => $citas,
                'Evaluaciones' => $evaluaciones
            ];
        }
        return $meses;
    }

    private function calcularPorcentaje($valor, $total)
    {
        return $total > 0 ? round(($valor / $total) * 100) : 0;
    }
}

