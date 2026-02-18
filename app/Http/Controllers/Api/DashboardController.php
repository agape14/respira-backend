<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class DashboardController extends Controller
{
    public function filtros(Request $request)
    {
        $userId = optional($request->user())->id ?? 'anon';

        $departamentos = Cache::remember("dashboard:departamentos:user:{$userId}", now()->addHours(8), function () {
            $q1 = DB::table('serumista_remunerados')
                ->selectRaw("LTRIM(RTRIM(DEPARTAMENTO)) as departamento")
                ->whereNotNull('DEPARTAMENTO')
                ->where('DEPARTAMENTO', '<>', '');

            $q2 = DB::table('serumista_equivalentes_remunerados')
                ->selectRaw("LTRIM(RTRIM(DEPARTAMENTO)) as departamento")
                ->whereNotNull('DEPARTAMENTO')
                ->where('DEPARTAMENTO', '<>', '');

            $union = $q1->union($q2);

            return DB::query()
                ->fromSub($union, 'd')
                ->select('departamento')
                ->distinct()
                ->orderBy('departamento')
                ->pluck('departamento')
                ->toArray();
        });

        $procesos = Cache::remember('dashboard:procesos:activos', now()->addMinutes(30), function () {
            return DB::table('procesos')
                ->where('activo', 1)
                ->orderBy('id_proceso')
                ->get()
                ->map(function ($p) {
                    return [
                        'id_proceso' => (int)$p->id_proceso,
                        'etiqueta' => $p->etiqueta,
                        'anio' => (int)$p->anio,
                        'corte' => $p->corte,
                    ];
                })
                ->toArray();
        });

        return response()->json([
            'departamentos' => array_map(fn ($d) => ['id' => $d, 'nombre' => $d], $departamentos),
            'procesos' => $procesos,
        ]);
    }

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
        $startTime = microtime(true);

        // Verificación temprana de autenticación - responder rápido si no está autenticado
        if (!$request->user()) {
            \Illuminate\Support\Facades\Log::warning('Dashboard: Usuario no autenticado');
            return response()->json([
                'error' => 'No autenticado',
                'message' => 'El token de autenticación es inválido o ha expirado'
            ], 401);
        }

        // Generar clave de caché basada en los filtros
        $departamento = $request->input('departamento', '');
        $institucion = $request->input('institucion', '');
        $modalidad = $request->input('modalidad', '');
        $idProceso = $request->input('id_proceso', '');
        $corteLegacy = $request->input('corte', '');

        $cacheKey = sprintf(
            'dashboard:data:%s:%s:%s:%s:%s',
            md5($departamento),
            md5($institucion),
            md5($modalidad),
            md5($idProceso),
            md5($corteLegacy)
        );

        try {
            // Verificar si existe en caché primero
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                \Illuminate\Support\Facades\Log::info("Dashboard: Datos obtenidos del caché en {$elapsed}ms", [
                    'cache_key' => $cacheKey,
                    'filters' => compact('departamento', 'institucion', 'modalidad', 'idProceso')
                ]);
                return response()->json($cached);
            }

            // Usar lock para evitar cálculos concurrentes del mismo caché
            $lockKey = 'dashboard:lock:' . $cacheKey;
            $lock = Cache::lock($lockKey, 120); // Lock por 120 segundos máximo

            try {
                // Intentar obtener el lock (esperar máximo 5 segundos)
                if ($lock->block(5)) {
                    // Verificar nuevamente el caché después de obtener el lock (puede que otro proceso ya lo haya calculado)
                    $cached = Cache::get($cacheKey);
                    if ($cached !== null) {
                        $lock->release();
                        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                        \Illuminate\Support\Facades\Log::info("Dashboard: Datos obtenidos del caché después del lock en {$elapsed}ms");
                        return response()->json($cached);
                    }

                    // Si no está en caché, calcular los datos
                    \Illuminate\Support\Facades\Log::info('Dashboard: Calculando datos (no en caché, con lock)', [
                        'cache_key' => $cacheKey,
                        'filters' => compact('departamento', 'institucion', 'modalidad', 'idProceso')
                    ]);

                    // Calcular los datos y guardar en caché
                    $calculateStart = microtime(true);
                    $result = $this->fetchDashboardData($request, $departamento, $institucion, $modalidad, $idProceso, $corteLegacy);
                    $calculateElapsed = round((microtime(true) - $calculateStart) * 1000, 2);
                    \Illuminate\Support\Facades\Log::info("Dashboard: Cálculo completado en {$calculateElapsed}ms");

                    // Guardar en caché (5 minutos de validez)
                    Cache::put($cacheKey, $result, now()->addMinutes(5));

                    $lock->release();
                    $data = $result;
                } else {
                    // No se pudo obtener el lock, esperar un poco y volver a intentar obtener del caché
                    \Illuminate\Support\Facades\Log::warning('Dashboard: No se pudo obtener lock, esperando...', ['cache_key' => $cacheKey]);
                    sleep(2);
                    $cached = Cache::get($cacheKey);
                    if ($cached !== null) {
                        return response()->json($cached);
                    }
                    // Si aún no hay caché, calcular de todas formas
                    $calculateStart = microtime(true);
                    $result = $this->fetchDashboardData($request, $departamento, $institucion, $modalidad, $idProceso, $corteLegacy);
                    $calculateElapsed = round((microtime(true) - $calculateStart) * 1000, 2);
                    \Illuminate\Support\Facades\Log::info("Dashboard: Cálculo completado sin lock en {$calculateElapsed}ms");
                    Cache::put($cacheKey, $result, now()->addMinutes(5));
                    $data = $result;
                }
            } catch (\Exception $e) {
                $lock->release();
                throw $e;
            }

            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: Respuesta enviada en {$elapsed}ms total");

            // Si el caché retornó datos, devolverlos como respuesta JSON
            return response()->json($data);
        } catch (\Exception $e) {
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            \Illuminate\Support\Facades\Log::error('Dashboard Error (outer)', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
                'elapsed_ms' => $elapsed,
                'cache_key' => $cacheKey
            ]);
            return response()->json([
                'error' => 'Error al obtener datos del dashboard',
                'message' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
                'line' => config('app.debug') ? $e->getLine() : null,
                'file' => config('app.debug') ? basename($e->getFile()) : null,
            ], 500);
        }
    }

    private function fetchDashboardData(Request $request, $departamento, $institucion, $modalidad, $idProceso, $corteLegacy)
    {
        $stepStartTime = microtime(true);
        $overallStart = microtime(true);

        try {
            \Illuminate\Support\Facades\Log::info('Dashboard: [PASO 1] Iniciando fetchDashboardData', [
                'filters' => compact('departamento', 'institucion', 'modalidad', 'idProceso', 'corteLegacy')
            ]);

            // ====================================================================================
            // 1. FILTRADO DE POBLACIÓN (Usuarios/Serumistas)
            // ====================================================================================

            $hasPopulationFilters = ($departamento || $institucion || $modalidad);
            $filteredUserIds = null; // Subquery de IDs (usuarios.id) pertenecientes al padrón + filtros
            $totalSerumistas = 0;    // Padrón (serumista_equivalentes_remunerados)

            // Resolver rango de fechas por id_proceso (o corte legacy)
            $stepStart = microtime(true);
            $dateRange = null; // ['start' => Carbon, 'end' => Carbon]
            $proceso = null;
            if ($idProceso) {
                $proceso = DB::table('procesos')->where('id_proceso', $idProceso)->first();
            } elseif ($corteLegacy) {
                $proceso = DB::table('procesos')->where('etiqueta', $corteLegacy)->first();
            }
            if ($proceso) {
                $dateRange = [
                    'start' => Carbon::parse($proceso->fecha_inicio)->startOfDay(),
                    'end' => Carbon::parse($proceso->fecha_fin)->endOfDay(),
                ];
            }
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 1.1] Proceso/rango de fechas resuelto en {$stepElapsed}ms");

            // Padrón (incluye REMUNERADOS y EQUIVALENTES)
            $stepStart = microtime(true);
            $padronBase = DB::table('serumista_equivalentes_remunerados');
            if ($departamento) $padronBase->where('DEPARTAMENTO', $departamento);
            if ($institucion) $padronBase->where('INSTITUCION', 'LIKE', '%' . $institucion . '%');

            if ($modalidad) {
                if ($modalidad === 'REMUNERADO') $padronBase->where('MODALIDAD', 'REMUNERADOS');
                if ($modalidad === 'EQUIVALENTE') $padronBase->where('MODALIDAD', 'EQUIVALENTES');
            }

            \Illuminate\Support\Facades\Log::info('Dashboard: [PASO 1.2] Ejecutando COUNT de padrón...');
            $totalSerumistas = (clone $padronBase)->count();
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 1.2] COUNT padrón completado en {$stepElapsed}ms", ['total' => $totalSerumistas]);

            // IDs de usuarios registrados que pertenecen al padrón (base para métricas de médicos)
            $stepStart = microtime(true);
            $userQuery = DB::table('usuarios')
                ->join('serumista_equivalentes_remunerados', DB::raw('CAST(usuarios.cmp AS VARCHAR)'), '=', DB::raw('CAST(serumista_equivalentes_remunerados.CMP AS VARCHAR)'))
                ->where('usuarios.estado', 1)
                ->select('usuarios.id');

            if ($departamento) $userQuery->where('serumista_equivalentes_remunerados.DEPARTAMENTO', $departamento);
            if ($institucion) $userQuery->where('serumista_equivalentes_remunerados.INSTITUCION', 'LIKE', '%' . $institucion . '%');
            if ($modalidad === 'REMUNERADO') $userQuery->where('serumista_equivalentes_remunerados.MODALIDAD', 'REMUNERADOS');
            if ($modalidad === 'EQUIVALENTE') $userQuery->where('serumista_equivalentes_remunerados.MODALIDAD', 'EQUIVALENTES');

            // IMPORTANT: usar subquery para evitar límite de 2100 parámetros en SQL Server
            $filteredUserIds = $userQuery;
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 1.3] Query de usuarios filtrados construida en {$stepElapsed}ms");

            // Helper para aplicar filtros a las queries de métricas
            $applyFilter = function($q, $userColumn = 'user_id') use ($filteredUserIds) {
                if ($filteredUserIds !== null) {
                    $q->whereIn($userColumn, $filteredUserIds);
                }
            };

            // Helper para aplicar rango de fechas (corte)
            $applyDateRange = function ($q, $dateColumn) use ($dateRange) {
                if ($dateRange !== null) {
                    $q->whereBetween($dateColumn, [$dateRange['start'], $dateRange['end']]);
                }
            };

            // Helper para columnas NVARCHAR con fechas (evita errores de conversión en SQL Server)
            $applyDateRangeNvarchar = function ($q, $dateColumn) use ($dateRange) {
                if ($dateRange === null) return;

                // Intenta múltiples formatos comunes:
                // 120/126/23: yyyy-mm-dd[ hh:mi:ss], 103: dd/mm/yyyy, 101: mm/dd/yyyy, 112: yyyymmdd
                $expr = "COALESCE(
                    TRY_CONVERT(datetime2, {$dateColumn}, 120),
                    TRY_CONVERT(datetime2, {$dateColumn}, 126),
                    TRY_CONVERT(datetime2, {$dateColumn}, 23),
                    TRY_CONVERT(datetime2, {$dateColumn}, 103),
                    TRY_CONVERT(datetime2, {$dateColumn}, 101),
                    TRY_CONVERT(datetime2, {$dateColumn}, 112),
                    TRY_CONVERT(datetime2, {$dateColumn})
                )";

                $q->whereRaw(
                    "{$expr} BETWEEN ? AND ?",
                    [$dateRange['start']->format('Y-m-d H:i:s'), $dateRange['end']->format('Y-m-d H:i:s')]
                );
            };

            // Helper para aplicar rango de fechas a múltiples columnas NVARCHAR de la vista materializada
            $applyDateRangeMultipleColumns = function ($q) use ($dateRange) {
                if ($dateRange === null) return;

                $startStr = $dateRange['start']->format('Y-m-d H:i:s');
                $endStr = $dateRange['end']->format('Y-m-d H:i:s');

                // Construir condición OR para todas las columnas de fecha
                // Verificar que TRY_CONVERT no sea NULL antes de comparar
                $q->where(function ($subQuery) use ($startStr, $endStr) {
                    $subQuery->whereRaw("TRY_CONVERT(datetime2, fecha_suicidio_agudo, 120) IS NOT NULL AND TRY_CONVERT(datetime2, fecha_suicidio_agudo, 120) BETWEEN ? AND ?", [$startStr, $endStr])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_suicidio_no_agudo, 120) IS NOT NULL AND TRY_CONVERT(datetime2, fecha_suicidio_no_agudo, 120) BETWEEN ? AND ?", [$startStr, $endStr])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_depresion, 120) IS NOT NULL AND TRY_CONVERT(datetime2, fecha_depresion, 120) BETWEEN ? AND ?", [$startStr, $endStr])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_ansiedad, 120) IS NOT NULL AND TRY_CONVERT(datetime2, fecha_ansiedad, 120) BETWEEN ? AND ?", [$startStr, $endStr])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_alcohol, 120) IS NOT NULL AND TRY_CONVERT(datetime2, fecha_alcohol, 120) BETWEEN ? AND ?", [$startStr, $endStr])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_burnout, 120) IS NOT NULL AND TRY_CONVERT(datetime2, fecha_burnout, 120) BETWEEN ? AND ?", [$startStr, $endStr]);
                });
            };

            // Determinar si podemos usar la vista/tabla materializada del dashboard.
            // En local puede fallar por dependencias a servidores/BD externos (ej. CMP02).
            $stepStart = microtime(true);
            \Illuminate\Support\Facades\Log::info('Dashboard: [PASO 2] Verificando si se puede usar vista materializada...');

            // Usar caché para evitar verificar en cada request
            $canUseTamizMaterialized = Cache::remember('dashboard:can_use_tamiz_materialized', now()->addHours(1), function () {
                try {
                    DB::table('dashboard_total_medicos_tamizaje')->select('user_id')->limit(1)->get();
                    return true;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Dashboard: No se puede usar vista materializada', [
                        'error' => $e->getMessage()
                    ]);
                    return false;
                }
            });
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 2] Verificación de vista materializada completada en {$stepElapsed}ms", ['can_use' => $canUseTamizMaterialized]);

            // ====================================================================================
            // 2. OBTENCIÓN DE MÉTRICAS (Con Filtros Aplicados)
            // ====================================================================================
            \Illuminate\Support\Facades\Log::info('Dashboard: [PASO 3] Iniciando obtención de métricas de evaluaciones...');
            $metricsStart = microtime(true);

            $tamizBase = null;
            if ($canUseTamizMaterialized) {
                \Illuminate\Support\Facades\Log::info('Dashboard: [PASO 3.1] Usando vista materializada para evaluaciones (OPTIMIZADO: consulta única)...');

                // OPTIMIZACIÓN: Hacer UNA sola consulta con agregaciones condicionales en lugar de 5 COUNTs separados
                $stepStart = microtime(true);

                // Construir query base desde cero para evitar problemas con parámetros al clonar
                $countQuery = DB::table('dashboard_total_medicos_tamizaje');

                // Aplicar filtros básicos
                if ($departamento) {
                    $countQuery->where('departamento', $departamento);
                }
                if ($institucion) {
                    $countQuery->where('institucion', 'LIKE', '%' . $institucion . '%');
                }
                if ($modalidad === 'REMUNERADO') {
                    $countQuery->where('modalidad', 'REMUNERADOS');
                }
                if ($modalidad === 'EQUIVALENTE') {
                    $countQuery->where('modalidad', 'EQUIVALENTES');
                }

                // Aplicar filtro de fecha usando helper
                $applyDateRangeMultipleColumns($countQuery);

                // UNA sola consulta con SUM de CASE WHEN para todos los conteos
                try {
                    $counts = $countQuery
                        ->selectRaw("
                            SUM(CASE
                                WHEN riesgo_suicida_no_agudo != 'Sin Registro'
                                     OR fecha_suicidio_agudo IS NOT NULL
                                     OR fecha_suicidio_no_agudo IS NOT NULL
                                THEN 1 ELSE 0 END) as total_asq,
                            SUM(CASE WHEN depresion != 'Sin registro' THEN 1 ELSE 0 END) as total_phq,
                            SUM(CASE WHEN ansiedad != 'Sin registro' THEN 1 ELSE 0 END) as total_gad,
                            SUM(CASE WHEN alcohol != 'Sin registro' THEN 1 ELSE 0 END) as total_audit,
                            SUM(CASE WHEN burnout != 'Sin registro' THEN 1 ELSE 0 END) as total_mbi
                        ")
                        ->first();
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Dashboard: Error en consulta optimizada de conteos', [
                        'error' => $e->getMessage(),
                        'sql' => $countQuery->toSql(),
                        'bindings' => $countQuery->getBindings(),
                        'date_range' => $dateRange ? [
                            'start' => $dateRange['start']->format('Y-m-d H:i:s'),
                            'end' => $dateRange['end']->format('Y-m-d H:i:s')
                        ] : null
                    ]);
                    throw $e;
                }

                $totalAsq = (int)($counts->total_asq ?? 0);
                $totalPhq = (int)($counts->total_phq ?? 0);
                $totalGad = (int)($counts->total_gad ?? 0);
                $totalAudit = (int)($counts->total_audit ?? 0);
                $totalMbi = (int)($counts->total_mbi ?? 0);

                // Guardar la query base para usar más adelante (reconstruir para estadísticas detalladas)
                $tamizBase = DB::table('dashboard_total_medicos_tamizaje');
                if ($departamento) $tamizBase->where('departamento', $departamento);
                if ($institucion) $tamizBase->where('institucion', 'LIKE', '%' . $institucion . '%');
                if ($modalidad === 'REMUNERADO') $tamizBase->where('modalidad', 'REMUNERADOS');
                if ($modalidad === 'EQUIVALENTE') $tamizBase->where('modalidad', 'EQUIVALENTES');
                // Aplicar filtro de fecha usando helper
                $applyDateRangeMultipleColumns($tamizBase);

                $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
                \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 3.1] Todos los conteos obtenidos en {$stepElapsed}ms (optimizado)", [
                    'asq' => $totalAsq,
                    'phq' => $totalPhq,
                    'gad' => $totalGad,
                    'audit' => $totalAudit,
                    'mbi' => $totalMbi
                ]);
            } else {
                \Illuminate\Support\Facades\Log::info('Dashboard: [PASO 3.2] Usando tablas base (fallback) para evaluaciones...');

                // Fallback: contar por USUARIO (distinct) desde tablas base (sin dependencias externas)
                $stepStart = microtime(true);
                $totalAsq = DB::table('asq5_responses')
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'fecha_registro'))
                    ->distinct('user_id')
                    ->count('user_id');
                $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
                \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 3.2.1] ASQ (fallback) contado en {$stepElapsed}ms", ['total' => $totalAsq]);

                $stepStart = microtime(true);
                $totalPhq = DB::table('phq9_responses')
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                    ->distinct('user_id')
                    ->count('user_id');
                $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
                \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 3.2.2] PHQ (fallback) contado en {$stepElapsed}ms", ['total' => $totalPhq]);

                $stepStart = microtime(true);
                $totalGad = DB::table('gad_responses')
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                    ->distinct('user_id')
                    ->count('user_id');
                $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
                \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 3.2.3] GAD (fallback) contado en {$stepElapsed}ms", ['total' => $totalGad]);

                $stepStart = microtime(true);
                $totalMbi = DB::table('mbi_responses')
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                    ->distinct('user_id')
                    ->count('user_id');
                $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
                \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 3.2.4] MBI (fallback) contado en {$stepElapsed}ms", ['total' => $totalMbi]);

                $stepStart = microtime(true);
                $totalAudit = DB::table('audit_responses')
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                    ->distinct('user_id')
                    ->count('user_id');
                $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
                \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 3.2.5] AUDIT (fallback) contado en {$stepElapsed}ms", ['total' => $totalAudit]);
            }

            $totalEvaluaciones = $totalAsq + $totalPhq + $totalGad + $totalMbi + $totalAudit;
            $metricsElapsed = round((microtime(true) - $metricsStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 3] Métricas de evaluaciones completadas en {$metricsElapsed}ms", ['total' => $totalEvaluaciones]);

            // Citas
            \Illuminate\Support\Facades\Log::info('Dashboard: [PASO 4] Iniciando consultas de citas...');
            $stepStart = microtime(true);
            $totalCitas = DB::table('citas')
                ->tap(fn ($q) => $applyFilter($q, 'paciente_id'))
                ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                ->count();
            $citasAtendidas = DB::table('citas')
                ->where('estado', 2)
                ->tap(fn ($q) => $applyFilter($q, 'paciente_id'))
                ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                ->count();
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 4] Citas consultadas en {$stepElapsed}ms", ['total' => $totalCitas, 'atendidas' => $citasAtendidas]);

            // Protocolos (caché de 30 minutos ya que no cambia frecuentemente)
            $stepStart = microtime(true);
            $protocolosActivos = Cache::remember('dashboard:protocolos_activos', now()->addMinutes(30), function () {
                return DB::table('curso_abordaje')->count();
            });
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 5] Protocolos obtenidos en {$stepElapsed}ms", ['total' => $protocolosActivos]);

            // ====================================================================================
            // 3. ESTADÍSTICAS DETALLADAS POR MODALIDAD - USANDO VISTAS DE LA BASE DE DATOS
            // ====================================================================================
            \Illuminate\Support\Facades\Log::info('Dashboard: [PASO 5.1] Iniciando estadísticas por modalidad...');
            $modalidadStart = microtime(true);

            // Total de Usuarios por Modalidad (padrón completo)
            $stepStart = microtime(true);
            $padronBreakdown = DB::table('serumista_equivalentes_remunerados');
            if ($departamento) $padronBreakdown->where('DEPARTAMENTO', $departamento);
            if ($institucion) $padronBreakdown->where('INSTITUCION', 'LIKE', '%' . $institucion . '%');
            if ($modalidad === 'REMUNERADO') $padronBreakdown->where('MODALIDAD', 'REMUNERADOS');
            if ($modalidad === 'EQUIVALENTE') $padronBreakdown->where('MODALIDAD', 'EQUIVALENTES');

            $totalRemunerados = (clone $padronBreakdown)->where('MODALIDAD', 'REMUNERADOS')->count();
            $totalEquivalentes = (clone $padronBreakdown)->where('MODALIDAD', 'EQUIVALENTES')->count();
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 5.1.1] Padrón por modalidad completado en {$stepElapsed}ms", ['remunerados' => $totalRemunerados, 'equivalentes' => $totalEquivalentes]);

            // Usuarios que accedieron (cálculo directo, consistente con filtros)
            $stepStart = microtime(true);
            $accedieronRemunerados = DB::table('usuarios')
                ->join('serumista_equivalentes_remunerados', DB::raw('CAST(usuarios.cmp AS VARCHAR)'), '=', DB::raw('CAST(serumista_equivalentes_remunerados.CMP AS VARCHAR)'))
                ->where('usuarios.estado', 1)
                ->where('usuarios.perfil_id', 3)
                ->where('serumista_equivalentes_remunerados.MODALIDAD', 'REMUNERADOS')
                ->whereNotIn('usuarios.cmp', ['088372','097481','044840','008190','097438'])
                ->when($filteredUserIds, fn ($q) => $q->whereIn('usuarios.id', $filteredUserIds))
                ->distinct('usuarios.cmp')
                ->count('usuarios.cmp');

            $accedieronEquivalentes = DB::table('usuarios')
                ->join('serumista_equivalentes_remunerados', DB::raw('CAST(usuarios.cmp AS VARCHAR)'), '=', DB::raw('CAST(serumista_equivalentes_remunerados.CMP AS VARCHAR)'))
                ->where('usuarios.estado', 1)
                ->where('usuarios.perfil_id', 3)
                ->where('serumista_equivalentes_remunerados.MODALIDAD', 'EQUIVALENTES')
                ->whereNotIn('usuarios.cmp', ['088372','097481','044840','008190','097438'])
                ->when($filteredUserIds, fn ($q) => $q->whereIn('usuarios.id', $filteredUserIds))
                ->distinct('usuarios.cmp')
                ->count('usuarios.cmp');
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 5.1.2] Usuarios que accedieron completado en {$stepElapsed}ms", ['remunerados' => $accedieronRemunerados, 'equivalentes' => $accedieronEquivalentes]);

            // Tamizados por Modalidad - Usar lógica del query: usuarios con evaluaciones,
            // REMUNERADOS= en serumista_remunerados, EQUIVALENTES= en serumista_equivalentes MODALIDAD=EQUIVALENTES (no en remunerados)
            $stepStart = microtime(true);
            $tamizadosCounts = $this->getTamizadosCounts($filteredUserIds, $departamento, $institucion, $modalidad, $dateRange, $applyDateRange, $applyDateRangeNvarchar);
            $tamizadosRemunerados = $tamizadosCounts['remunerados'];
            $tamizadosEquivalentes = $tamizadosCounts['equivalentes'];
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 5.1.3] Tamizados por modalidad completado en {$stepElapsed}ms", ['remunerados' => $tamizadosRemunerados, 'equivalentes' => $tamizadosEquivalentes]);

            // Citas registradas (total de citas)
            $totalCitasRegistradas = $totalCitas;

            // Citas de Intervención Breve atendidas (citas con estado 2 que tienen sesión)
            $stepStart = microtime(true);
            $citasIntervencionBreveAtendidas = DB::table('citas')
                ->join('citas_finalizados', 'citas.id', '=', 'citas_finalizados.cita_id')
                ->where('citas.estado', 2)
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('citas.paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRange($q, 'citas.fecha'))
                ->count();
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 5.1.4] Citas intervención breve completado en {$stepElapsed}ms", ['total' => $citasIntervencionBreveAtendidas]);

            $modalidadElapsed = round((microtime(true) - $modalidadStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 5.1] Estadísticas por modalidad completadas en {$modalidadElapsed}ms total");

            // ====================================================================================
            // 4. DERIVACIONES
            // ====================================================================================
            \Illuminate\Support\Facades\Log::info('Dashboard: [PASO 6] Iniciando consultas de derivaciones...');
            $derivStart = microtime(true);

            // Total de casos derivados - Usar lógica de DerivacionController (sin filtro padrón para coincidir con /derivaciones)
            $stepStart = microtime(true);
            $essaludHighRisk = $this->getHighRiskCountByEntity('ESSALUD', null);
            $minsaHighRisk = $this->getHighRiskCountByEntity('MINSA', null);
            $highRiskTotal = $essaludHighRisk + $minsaHighRisk;

            // También contar derivados de la tabla (aunque esté vacía)
            $derivadosFromTableQuery = DB::table('derivados');
            if ($filteredUserIds !== null) {
                $derivadosFromTableQuery->whereIn('paciente_id', $filteredUserIds);
            }
            $applyDateRangeNvarchar($derivadosFromTableQuery, 'fecha');
            $derivadosFromTable = $derivadosFromTableQuery
                ->selectRaw('COUNT(DISTINCT paciente_id) as total')
                ->value('total') ?? 0;

            // Total: combinamos ambos (los de alto riesgo ya incluyen los de la tabla derivados)
            $totalCasosDerivados = max($derivadosFromTable, $highRiskTotal);
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 6.1] Total casos derivados obtenido en {$stepElapsed}ms", [
                'total' => $totalCasosDerivados,
                'high_risk_total' => $highRiskTotal,
                'derivados_from_table' => $derivadosFromTable
            ]);

            // Derivados desde Tamizaje - Todos los pacientes de alto riesgo son por tamizaje
            $stepStart = microtime(true);
            $derivadosTamizaje = $highRiskTotal; // Todos los pacientes de alto riesgo son por tamizaje
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 6.2] Derivados tamizaje obtenido en {$stepElapsed}ms", ['total' => $derivadosTamizaje]);

            // Derivados desde Intervención Breve
            $stepStart = microtime(true);
            $derivadosIntervencionBreveQuery = DB::table('derivados')
                ->where('tipo', 'M'); // M = manual (desde atención)
            if ($filteredUserIds !== null) {
                $derivadosIntervencionBreveQuery->whereIn('paciente_id', $filteredUserIds);
            }
            $applyDateRangeNvarchar($derivadosIntervencionBreveQuery, 'fecha');
            $derivadosIntervencionBreve = $derivadosIntervencionBreveQuery
                ->selectRaw('COUNT(DISTINCT paciente_id) as total')
                ->value('total') ?? 0;
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 6.3] Derivados intervención breve obtenido en {$stepElapsed}ms", ['total' => $derivadosIntervencionBreve]);

            // Derivaciones por Institución (ESSALUD y MINSA) - Usar pacientes de alto riesgo
            $stepStart = microtime(true);
            $derivacionesEssalud = $essaludHighRisk;
            $derivacionesMinsa = $minsaHighRisk;
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 6.4-6.5] Derivaciones por entidad obtenidas en {$stepElapsed}ms", [
                'essalud' => $derivacionesEssalud,
                'minsa' => $derivacionesMinsa
            ]);

            // Derivaciones atendidas (tabla derivaciones_atencion)
            $stepStart = microtime(true);
            $derivacionesAtendidasEssalud = DB::table('derivaciones_atencion')
                ->where('entidad', 'LIKE', '%ESSALUD%')
                ->when($filteredUserIds, fn ($q) => $q->whereIn('paciente_id', $filteredUserIds))
                ->tap(fn ($q) => $applyDateRange($q, 'fecha_atencion'))
                ->count();

            $derivacionesAtendidasMinsa = DB::table('derivaciones_atencion')
                ->where('entidad', 'LIKE', '%MINSA%')
                ->when($filteredUserIds, fn ($q) => $q->whereIn('paciente_id', $filteredUserIds))
                ->tap(fn ($q) => $applyDateRange($q, 'fecha_atencion'))
                ->count();

            $totalDerivacionesAtendidas = $derivacionesAtendidasEssalud + $derivacionesAtendidasMinsa;
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 6.6] Derivaciones atendidas obtenidas en {$stepElapsed}ms", [
                'essalud' => $derivacionesAtendidasEssalud,
                'minsa' => $derivacionesAtendidasMinsa,
                'total' => $totalDerivacionesAtendidas
            ]);

            // Derivaciones desde Tamizaje por tipo de evaluación - Usar pacientes de alto riesgo directamente
            \Illuminate\Support\Facades\Log::info('Dashboard: [PASO 6.7] Iniciando derivaciones por tipo de evaluación...');

            $stepStart = microtime(true);
            // ASQ: solo Riesgo suicida agudo/inminente (igual que DerivacionController)
            $derivacionesAsqQuery = DB::table('usuarios')
                ->join('asq5_responses', 'usuarios.id', '=', 'asq5_responses.user_id')
                ->where('asq5_responses.resultado', 'Riesgo suicida agudo/inminente')
                ->whereRaw('asq5_responses.id = (SELECT MAX(id) FROM asq5_responses r2 WHERE r2.user_id = asq5_responses.user_id)')
                ->where('usuarios.estado', 1);
            $derivacionesAsq = $derivacionesAsqQuery->selectRaw('COUNT(DISTINCT usuarios.id) as total')->value('total') ?? 0;
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 6.7.1] Derivaciones ASQ obtenidas en {$stepElapsed}ms", ['total' => $derivacionesAsq]);

            $stepStart = microtime(true);
            // PHQ: pacientes con riesgo alto
            $derivacionesPhqQuery = DB::table('usuarios')
                ->join('phq9_responses', 'usuarios.id', '=', 'phq9_responses.user_id')
                ->where('phq9_responses.riesgo', 'Riesgo alto')
                ->whereRaw('phq9_responses.id_encuesta = (SELECT MAX(id_encuesta) FROM phq9_responses r2 WHERE r2.user_id = phq9_responses.user_id)')
                ->where('usuarios.estado', 1);
            $derivacionesPhq = $derivacionesPhqQuery->selectRaw('COUNT(DISTINCT usuarios.id) as total')->value('total') ?? 0;
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 6.7.2] Derivaciones PHQ obtenidas en {$stepElapsed}ms", ['total' => $derivacionesPhq]);

            $stepStart = microtime(true);
            // GAD: pacientes con riesgo alto
            $derivacionesGadQuery = DB::table('usuarios')
                ->join('gad_responses', 'usuarios.id', '=', 'gad_responses.user_id')
                ->where('gad_responses.riesgo', 'Riesgo alto')
                ->whereRaw('gad_responses.id_encuesta = (SELECT MAX(id_encuesta) FROM gad_responses r2 WHERE r2.user_id = gad_responses.user_id)')
                ->where('usuarios.estado', 1);
            $derivacionesGad = $derivacionesGadQuery->selectRaw('COUNT(DISTINCT usuarios.id) as total')->value('total') ?? 0;
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 6.7.3] Derivaciones GAD obtenidas en {$stepElapsed}ms", ['total' => $derivacionesGad]);

            $stepStart = microtime(true);
            // MBI: pacientes con presencia de burnout
            $derivacionesMbiQuery = DB::table('usuarios')
                ->join('mbi_responses', 'usuarios.id', '=', 'mbi_responses.user_id')
                ->where(function($q) {
                    $q->where('mbi_responses.riesgoCE', 'Presencia de burnout')
                      ->orWhere('mbi_responses.riesgoDP', 'Presencia de burnout')
                      ->orWhere('mbi_responses.riesgoRP', 'Presencia de burnout');
                })
                ->whereRaw('mbi_responses.id_encuesta = (SELECT MAX(id_encuesta) FROM mbi_responses r2 WHERE r2.user_id = mbi_responses.user_id)')
                ->where('usuarios.estado', 1);
            $derivacionesMbi = $derivacionesMbiQuery->selectRaw('COUNT(DISTINCT usuarios.id) as total')->value('total') ?? 0;
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 6.7.4] Derivaciones MBI obtenidas en {$stepElapsed}ms", ['total' => $derivacionesMbi]);

            $stepStart = microtime(true);
            // AUDIT: Consumo problemático, Probable consumo problemático, Dependencia (igual que DerivacionController)
            $derivacionesAuditQuery = DB::table('usuarios')
                ->join('audit_responses', 'usuarios.id', '=', 'audit_responses.user_id')
                ->whereIn('audit_responses.riesgo', ['Consumo problemático', 'Probable consumo problemático', 'Dependencia'])
                ->whereRaw('audit_responses.id_encuesta = (SELECT MAX(id_encuesta) FROM audit_responses r2 WHERE r2.user_id = audit_responses.user_id)')
                ->where('usuarios.estado', 1);
            $derivacionesAudit = $derivacionesAuditQuery->selectRaw('COUNT(DISTINCT usuarios.id) as total')->value('total') ?? 0;
            $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 6.7.5] Derivaciones AUDIT obtenidas en {$stepElapsed}ms", ['total' => $derivacionesAudit]);

            $derivElapsed = round((microtime(true) - $derivStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 6] Todas las derivaciones completadas en {$derivElapsed}ms total");

            // Estadísticas Generales (Top Cards)
            $estadisticas_generales = [
                'total_serumistas' => $totalSerumistas,
                'total_remunerados' => $totalRemunerados,
                'total_equivalentes' => $totalEquivalentes,
                'total_no_clasificados' => 0,
                'accedieron_total' => $accedieronRemunerados + $accedieronEquivalentes,
                'accedieron_remunerados' => $accedieronRemunerados,
                'accedieron_equivalentes' => $accedieronEquivalentes,
                'accedieron_no_clasificados' => 0,
                'tamizados_total' => $tamizadosCounts['total'],
                'tamizados_remunerados' => $tamizadosRemunerados,
                'tamizados_equivalentes' => $tamizadosEquivalentes,
                'evaluaciones_totales' => $totalEvaluaciones,
                'citas_registradas' => $totalCitasRegistradas,
                'citas_intervencion_breve_atendidas' => $citasIntervencionBreveAtendidas,
                'citas_atendidas' => $citasAtendidas,
                'protocolos_activos' => $protocolosActivos,
                'total_casos_derivados' => $totalCasosDerivados,
                'derivados_tamizaje' => $derivadosTamizaje,
                'derivados_intervencion_breve' => $derivadosIntervencionBreve,
                'derivaciones_essalud' => $derivacionesEssalud,
                'derivaciones_minsa' => $derivacionesMinsa,
                'derivaciones_atendidas_total' => $totalDerivacionesAtendidas,
                'derivaciones_atendidas_essalud' => $derivacionesAtendidasEssalud,
                'derivaciones_atendidas_minsa' => $derivacionesAtendidasMinsa,
                'derivaciones_tamizaje_asq' => $derivacionesAsq,
                'derivaciones_tamizaje_phq' => $derivacionesPhq,
                'derivaciones_tamizaje_gad' => $derivacionesGad,
                'derivaciones_tamizaje_mbi' => $derivacionesMbi,
                'derivaciones_tamizaje_audit' => $derivacionesAudit,
            ];

            // --------------------------------------------------------------------------------
            // Evaluaciones por Tipo (Bar Chart Horizontal)
            // --------------------------------------------------------------------------------
            $evaluaciones_por_tipo = [
                ['name' => 'ASQ-5', 'total' => $totalAsq, 'color' => '#8884d8'],
                ['name' => 'PHQ-9', 'total' => $totalPhq, 'color' => '#82ca9d'],
                ['name' => 'GAD-7', 'total' => $totalGad, 'color' => '#ffc658'],
                ['name' => 'MBI', 'total' => $totalMbi, 'color' => '#ff8042'],
                ['name' => 'AUDIT', 'total' => $totalAudit, 'color' => '#0088fe'],
            ];

            // (Se calcula más abajo como distribución 1..5 según la lógica de PowerBI)

            if ($canUseTamizMaterialized && $tamizBase !== null) {
                \Illuminate\Support\Facades\Log::info('Dashboard: [PASO 7] Iniciando cálculo de distribuciones estadísticas...');
                $distribucionesStart = microtime(true);

                // OPTIMIZACIÓN CRÍTICA: Una sola consulta SELECT que trae solo las columnas necesarias
                // y procesar en memoria. Esto evita múltiples escaneos con filtros de fecha complejos
                $stepStart = microtime(true);

                try {
                    // Obtener solo las columnas necesarias (mucho más eficiente que múltiples GROUP BY)
                    // cuando hay filtros de fecha complejos con TRY_CONVERT
                    $distribucionesData = (clone $tamizBase)
                        ->select([
                            'user_id',
                            'FlagMasculino',
                            'edad',
                            'grupo_etareo',
                            'depresion',
                            'ansiedad',
                            'alcohol',
                            'burnout',
                            'riesgo_suicida_agudo',
                            'riesgo_suicida_no_agudo'
                        ])
                        ->get();

                    $fetchElapsed = round((microtime(true) - $stepStart) * 1000, 2);
                    \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 7.1.1] Datos obtenidos en {$fetchElapsed}ms", ['rows' => $distribucionesData->count()]);

                    // Procesar en memoria (muy rápido en PHP)
                    $processStart = microtime(true);
                    $sexoCounts = [];
                    $edadCounts = [];
                    $phqCounts = [];
                    $gadCounts = [];
                    $auditCounts = [];
                    $mbiCounts = [];
                    $analisis_asq = ['rsa_si' => 0, 'rsa_no' => 0, 'rsna_si' => 0, 'rsna_sin_riesgo' => 0];

                    foreach ($distribucionesData as $row) {
                        // Sexo (incluir No definido para que suma = total)
                        if (in_array($row->FlagMasculino ?? null, ['Masculino', 'Femenino'])) {
                            $sexoCounts[$row->FlagMasculino] = ($sexoCounts[$row->FlagMasculino] ?? 0) + 1;
                        } else {
                            $sexoCounts['No definido'] = ($sexoCounts['No definido'] ?? 0) + 1;
                        }
                        // Edad: rangos 23-25, 26-30, 31-35, 36-40, 41-45, 46-50, 51-55, 56-60
                        $edadVal = isset($row->edad) ? (int) $row->edad : null;
                        $grupoEdad = $this->bucketEdadRango($edadVal);
                        $edadCounts[$grupoEdad] = ($edadCounts[$grupoEdad] ?? 0) + 1;
                        // PHQ
                        if ($row->depresion) {
                            $phqCounts[$row->depresion] = ($phqCounts[$row->depresion] ?? 0) + 1;
                        }
                        // GAD
                        if ($row->ansiedad) {
                            $gadCounts[$row->ansiedad] = ($gadCounts[$row->ansiedad] ?? 0) + 1;
                        }
                        // AUDIT
                        if ($row->alcohol) {
                            $auditCounts[$row->alcohol] = ($auditCounts[$row->alcohol] ?? 0) + 1;
                        }
                        // MBI
                        if ($row->burnout) {
                            $mbiCounts[$row->burnout] = ($mbiCounts[$row->burnout] ?? 0) + 1;
                        }
                        // ASQ
                        if ($row->riesgo_suicida_agudo === 'Sí') $analisis_asq['rsa_si']++;
                        if ($row->riesgo_suicida_agudo === 'No') $analisis_asq['rsa_no']++;
                        if ($row->riesgo_suicida_no_agudo === 'Sí') $analisis_asq['rsna_si']++;
                        if ($row->riesgo_suicida_no_agudo === 'Sin riesgo') $analisis_asq['rsna_sin_riesgo']++;
                    }

                    $processElapsed = round((microtime(true) - $processStart) * 1000, 2);
                    \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 7.1.2] Procesamiento en memoria completado en {$processElapsed}ms");

                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Dashboard: Error en cálculo de distribuciones', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    // Valores por defecto en caso de error
                    $sexoCounts = [];
                    $edadCounts = [];
                    $phqCounts = [];
                    $gadCounts = [];
                    $auditCounts = [];
                    $mbiCounts = [];
                    $analisis_asq = ['rsa_si' => 0, 'rsa_no' => 0, 'rsna_si' => 0, 'rsna_sin_riesgo' => 0];
                }

                $stepElapsed = round((microtime(true) - $stepStart) * 1000, 2);
                \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 7.1] Agregaciones completadas en {$stepElapsed}ms total");

                // Construir arrays finales para respuesta
                $distribucion_sexo = [
                    ['name' => 'Masculino', 'value' => (int)($sexoCounts['Masculino'] ?? 0), 'color' => '#3b82f6'],
                    ['name' => 'Femenino', 'value' => (int)($sexoCounts['Femenino'] ?? 0), 'color' => '#ec4899'],
                    ['name' => 'No definido', 'value' => (int)($sexoCounts['No definido'] ?? 0), 'color' => '#6b7280'],
                ];

                // Grupos etáreos: 23-25, 26-30, 31-35, 36-40, 41-45, 46-50, 51-55, 56-60 + Menor 23, Mayor 60, No definido
                $edadOrder = [
                    'Menor de 23', '23-25', '26-30', '31-35', '36-40', '41-45', '46-50', '51-55', '56-60', 'Mayor de 60', 'No definido'
                ];
                $distribucion_edad = [];
                foreach ($edadOrder as $g) {
                    if (isset($edadCounts[$g]) && $edadCounts[$g] > 0) {
                        $distribucion_edad[] = ['grupo' => $g, 'cantidad' => (int)$edadCounts[$g]];
                    }
                }

                $distribucion_phq = [
                    ['nivel' => 'Riesgo alto', 'cantidad' => (int)($phqCounts['Riesgo alto'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Riesgo moderado', 'cantidad' => (int)($phqCounts['Riesgo moderado'] ?? 0), 'color' => '#f97316'],
                    ['nivel' => 'Riesgo leve', 'cantidad' => (int)($phqCounts['Riesgo leve'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => 'Sin riesgo', 'cantidad' => (int)($phqCounts['Sin riesgo'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($phqCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                $distribucion_gad = [
                    ['nivel' => 'Riesgo alto', 'cantidad' => (int)($gadCounts['Riesgo alto'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Riesgo moderado', 'cantidad' => (int)($gadCounts['Riesgo moderado'] ?? 0), 'color' => '#f97316'],
                    ['nivel' => 'Riesgo leve', 'cantidad' => (int)($gadCounts['Riesgo leve'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => 'Sin riesgo', 'cantidad' => (int)($gadCounts['Sin riesgo'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($gadCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                $distribucion_audit = [
                    ['nivel' => 'Consumo problemático', 'cantidad' => (int)($auditCounts['Consumo problemático'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Consumo riesgoso', 'cantidad' => (int)($auditCounts['Consumo riesgoso'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => 'Riesgo bajo', 'cantidad' => (int)($auditCounts['Riesgo bajo'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($auditCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                $distribucion_mbi = [
                    ['nivel' => 'Presencia', 'cantidad' => (int)($mbiCounts['Presencia'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Ausencia', 'cantidad' => (int)($mbiCounts['Ausencia'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($mbiCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                // Total por concepto: peor nivel por tamizado (ALTO > MODERADO > LEVE > SIN RIESGO > SIN REGISTRO)
                $conceptoCounts = ['ALTO' => 0, 'MODERADO' => 0, 'LEVE' => 0, 'SIN RIESGO' => 0, 'SIN REGISTRO' => 0];
                foreach ($distribucionesData as $row) {
                    $concepto = $this->calcularConceptoTamizado($row);
                    $conceptoCounts[$concepto] = ($conceptoCounts[$concepto] ?? 0) + 1;
                }
                $total_por_concepto = [
                    ['nivel' => '1.ALTO', 'cantidad' => (int)($conceptoCounts['ALTO'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => '2.MODERADO', 'cantidad' => (int)($conceptoCounts['MODERADO'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => '3.LEVE', 'cantidad' => (int)($conceptoCounts['LEVE'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => '4.SIN RIESGO', 'cantidad' => (int)($conceptoCounts['SIN RIESGO'] ?? 0), 'color' => '#9ca3af'],
                    ['nivel' => '5.SIN REGISTRO', 'cantidad' => (int)($conceptoCounts['SIN REGISTRO'] ?? 0), 'color' => '#6b7280'],
                ];

                $distribucionesElapsed = round((microtime(true) - $distribucionesStart) * 1000, 2);
                \Illuminate\Support\Facades\Log::info("Dashboard: [PASO 7] Distribuciones estadísticas completadas en {$distribucionesElapsed}ms total");
            } else {
                // --------------------------------------------------------------------------------
                // Fallback DEV: sin CMP02 -> usar tablas base + lógica de "último/prioridad por usuario"
                // --------------------------------------------------------------------------------
                // Sexo desde mat.FlagMasculino (Mat_Colegiado); fallback a usuarios.sexo si mat no existe
                $sexoCase = "
                    CASE
                        WHEN mat.FlagMasculino IS NOT NULL THEN
                            CASE
                                WHEN TRY_CONVERT(INT, mat.FlagMasculino) = 1 OR UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(10))))) = 'M' THEN 'M'
                                WHEN TRY_CONVERT(INT, mat.FlagMasculino) = 0 OR UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(10))))) = 'F' THEN 'F'
                                WHEN UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(20))))) LIKE '%MASCULINO%' THEN 'M'
                                WHEN UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(20))))) LIKE '%FEMENINO%' THEN 'F'
                                ELSE NULL
                            END
                        ELSE
                            CASE
                                WHEN TRY_CONVERT(INT, usuarios.sexo) = 1 OR UPPER(LTRIM(RTRIM(CAST(usuarios.sexo AS VARCHAR(10))))) = 'M' THEN 'M'
                                WHEN TRY_CONVERT(INT, usuarios.sexo) = 0 OR UPPER(LTRIM(RTRIM(CAST(usuarios.sexo AS VARCHAR(10))))) = 'F' THEN 'F'
                                ELSE NULL
                            END
                    END
                ";
                $sexoBase = DB::table('usuarios')
                    ->leftJoin(DB::raw('[CMP02].[db_cmp].[dbo].[Mat_Colegiado] as mat'), DB::raw('CAST(usuarios.cmp AS VARCHAR(20))'), '=', DB::raw('CAST(mat.Colegiado_Id AS VARCHAR(20))'))
                    ->where('usuarios.estado', 1);
                if ($hasPopulationFilters) {
                    $sexoBase->whereIn('usuarios.id', $filteredUserIds);
                } else {
                    $tu = DB::table('asq5_responses')->select('user_id')->distinct()
                        ->union(DB::table('phq9_responses')->select('user_id')->distinct())
                        ->union(DB::table('gad_responses')->select('user_id')->distinct())
                        ->union(DB::table('mbi_responses')->select('user_id')->distinct())
                        ->union(DB::table('audit_responses')->select('user_id')->distinct());
                    $sexoBase->whereIn('usuarios.id', DB::query()->fromSub($tu, 't')->select('user_id'));
                }
                $sexoQuery = (clone $sexoBase)
                    ->selectRaw("{$sexoCase} as sexo_norm, COUNT(*) as count")
                    ->whereRaw("({$sexoCase}) IS NOT NULL")
                    ->groupByRaw($sexoCase);
                try {
                    $sexoRows = (clone $sexoQuery)->pluck('count', 'sexo_norm')->toArray();
                } catch (\Throwable $e) {
                    // Si CMP02 no disponible, usar solo usuarios.sexo
                    $sexoCaseAlt = "CASE WHEN TRY_CONVERT(int, usuarios.sexo) = 1 OR UPPER(LTRIM(RTRIM(CAST(usuarios.sexo AS VARCHAR(10))))) = 'M' THEN 'M' WHEN TRY_CONVERT(int, usuarios.sexo) = 0 OR UPPER(LTRIM(RTRIM(CAST(usuarios.sexo AS VARCHAR(10))))) = 'F' THEN 'F' ELSE NULL END";
                    $sexoRows = DB::table('usuarios')
                        ->selectRaw("{$sexoCaseAlt} as sexo_norm, COUNT(*) as count")
                        ->whereRaw("{$sexoCaseAlt} IS NOT NULL")
                        ->when($filteredUserIds !== null, fn ($q) => $q->whereIn('id', $filteredUserIds))
                        ->groupByRaw($sexoCaseAlt)
                        ->pluck('count', 'sexo_norm')
                        ->toArray();
                }
                $mCount = (int)($sexoRows['M'] ?? 0);
                $fCount = (int)($sexoRows['F'] ?? 0);
                $sexoTotal = $mCount + $fCount;
                $noDefinidoSexo = max(0, ($tamizadosCounts['total'] ?? $sexoTotal) - $sexoTotal);
                $distribucion_sexo = [
                    ['name' => 'Masculino', 'value' => $mCount, 'color' => '#3b82f6'],
                    ['name' => 'Femenino', 'value' => $fCount, 'color' => '#ec4899'],
                    ['name' => 'No definido', 'value' => $noDefinidoSexo, 'color' => '#6b7280'],
                ];

                // Edad: rangos 23-25, 26-30, ... 56-60. Sin filtros de padrón usar todos los tamizados
                $applyFilterEdad = $hasPopulationFilters ? $applyFilter : fn ($q, $col = 'user_id') => $q;
                $distribucion_edad = $this->getDistribucionEdadFallback($filteredUserIds, $applyFilterEdad, $applyDateRange, $applyDateRangeNvarchar, $tamizadosCounts['total'] ?? 0);

                // PHQ (último/prioridad por usuario)
                $phqSub = DB::table('phq9_responses')
                    ->selectRaw("
                        user_id, riesgo,
                        ROW_NUMBER() OVER (
                            PARTITION BY user_id
                            ORDER BY
                                CASE WHEN riesgo IN ('Alto', 'Riesgo alto', 'Riesgo Alto') THEN 1 ELSE 2 END,
                                fecha DESC,
                                id_encuesta DESC
                        ) as rn
                    ")
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'));

                $phqCase = "
                    CASE
                        WHEN LOWER(riesgo) LIKE '%alto%' THEN 'Riesgo alto'
                        WHEN LOWER(riesgo) LIKE '%moder%' THEN 'Riesgo moderado'
                        WHEN LOWER(riesgo) LIKE '%leve%' THEN 'Riesgo leve'
                        WHEN LOWER(riesgo) LIKE '%sin riesgo%' THEN 'Sin riesgo'
                        ELSE 'Sin registro'
                    END
                ";
                $phqCounts = DB::query()->fromSub($phqSub, 'p')
                    ->where('rn', 1)
                    ->selectRaw("{$phqCase} as nivel, COUNT(*) as cantidad")
                    ->groupByRaw($phqCase)
                    ->pluck('cantidad', 'nivel')
                    ->toArray();
                $distribucion_phq = [
                    ['nivel' => 'Riesgo alto', 'cantidad' => (int)($phqCounts['Riesgo alto'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Riesgo moderado', 'cantidad' => (int)($phqCounts['Riesgo moderado'] ?? 0), 'color' => '#f97316'],
                    ['nivel' => 'Riesgo leve', 'cantidad' => (int)($phqCounts['Riesgo leve'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => 'Sin riesgo', 'cantidad' => (int)($phqCounts['Sin riesgo'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($phqCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                // GAD (último/prioridad por usuario)
                $gadSub = DB::table('gad_responses')
                    ->selectRaw("
                        user_id, riesgo,
                        ROW_NUMBER() OVER (
                            PARTITION BY user_id
                            ORDER BY
                                CASE WHEN riesgo IN ('Riesgo alto', 'Riesgo Alto', 'Alto') THEN 1 ELSE 2 END,
                                fecha DESC,
                                id_encuesta DESC
                        ) as rn
                    ")
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'));

                $gadCase = "
                    CASE
                        WHEN LOWER(riesgo) LIKE '%alto%' THEN 'Riesgo alto'
                        WHEN LOWER(riesgo) LIKE '%moder%' THEN 'Riesgo moderado'
                        WHEN LOWER(riesgo) LIKE '%leve%' THEN 'Riesgo leve'
                        WHEN LOWER(riesgo) LIKE '%sin riesgo%' THEN 'Sin riesgo'
                        ELSE 'Sin registro'
                    END
                ";
                $gadCounts = DB::query()->fromSub($gadSub, 'g')
                    ->where('rn', 1)
                    ->selectRaw("{$gadCase} as nivel, COUNT(*) as cantidad")
                    ->groupByRaw($gadCase)
                    ->pluck('cantidad', 'nivel')
                    ->toArray();
                $distribucion_gad = [
                    ['nivel' => 'Riesgo alto', 'cantidad' => (int)($gadCounts['Riesgo alto'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Riesgo moderado', 'cantidad' => (int)($gadCounts['Riesgo moderado'] ?? 0), 'color' => '#f97316'],
                    ['nivel' => 'Riesgo leve', 'cantidad' => (int)($gadCounts['Riesgo leve'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => 'Sin riesgo', 'cantidad' => (int)($gadCounts['Sin riesgo'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($gadCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                // AUDIT (último/prioridad por usuario)
                $auditSub = DB::table('audit_responses')
                    ->selectRaw("
                        user_id, riesgo,
                        ROW_NUMBER() OVER (
                            PARTITION BY user_id
                            ORDER BY
                                CASE WHEN riesgo IN ('Probable consumo problemático', 'Consumo riesgoso') THEN 1 ELSE 2 END,
                                fecha DESC,
                                id_encuesta DESC
                        ) as rn
                    ")
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'));

                $auditCase = "
                    CASE
                        WHEN riesgo IN ('Probable consumo problemático', 'Consumo problemático') THEN 'Consumo problemático'
                        WHEN riesgo IN ('Consumo riesgoso') THEN 'Consumo riesgoso'
                        WHEN riesgo IN ('Bajo', 'Riesgo bajo') THEN 'Riesgo bajo'
                        ELSE 'Sin registro'
                    END
                ";
                $auditCounts = DB::query()->fromSub($auditSub, 'a')
                    ->where('rn', 1)
                    ->selectRaw("{$auditCase} as nivel, COUNT(*) as cantidad")
                    ->groupByRaw($auditCase)
                    ->pluck('cantidad', 'nivel')
                    ->toArray();
                $distribucion_audit = [
                    ['nivel' => 'Consumo problemático', 'cantidad' => (int)($auditCounts['Consumo problemático'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Consumo riesgoso', 'cantidad' => (int)($auditCounts['Consumo riesgoso'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => 'Riesgo bajo', 'cantidad' => (int)($auditCounts['Riesgo bajo'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($auditCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                // MBI (último/prioridad por usuario) - usamos riesgoCE
                $mbiSub = DB::table('mbi_responses')
                    ->selectRaw("
                        user_id, riesgoCE,
                        ROW_NUMBER() OVER (
                            PARTITION BY user_id
                            ORDER BY
                                CASE WHEN riesgoCE = 'Presencia de burnout' THEN 1 ELSE 2 END,
                                fecha DESC,
                                id_encuesta DESC
                        ) as rn
                    ")
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'));

                $mbiCase = "
                    CASE
                        WHEN riesgoCE = 'Presencia de burnout' THEN 'Presencia'
                        WHEN riesgoCE = 'Ausencia de burnout' THEN 'Ausencia'
                        ELSE 'Sin registro'
                    END
                ";
                $mbiCounts = DB::query()->fromSub($mbiSub, 'm')
                    ->where('rn', 1)
                    ->selectRaw("{$mbiCase} as nivel, COUNT(*) as cantidad")
                    ->groupByRaw($mbiCase)
                    ->pluck('cantidad', 'nivel')
                    ->toArray();
                $distribucion_mbi = [
                    ['nivel' => 'Presencia', 'cantidad' => (int)($mbiCounts['Presencia'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Ausencia', 'cantidad' => (int)($mbiCounts['Ausencia'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($mbiCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                // Total por concepto desde tablas base (peor nivel por tamizado)
                $total_por_concepto = $this->getTotalPorConceptoFallback($filteredUserIds, $applyFilter, $applyDateRange, $applyDateRangeNvarchar);

                // ASQ (último/prioridad por usuario)
                $asqExpr = "COALESCE(
                    TRY_CONVERT(datetime2, fecha_registro, 120),
                    TRY_CONVERT(datetime2, fecha_registro, 126),
                    TRY_CONVERT(datetime2, fecha_registro, 23),
                    TRY_CONVERT(datetime2, fecha_registro, 103),
                    TRY_CONVERT(datetime2, fecha_registro, 101),
                    TRY_CONVERT(datetime2, fecha_registro, 112),
                    TRY_CONVERT(datetime2, fecha_registro)
                )";
                $asqSub = DB::table('asq5_responses')
                    ->selectRaw("
                        user_id, resultado,
                        ROW_NUMBER() OVER (
                            PARTITION BY user_id
                            ORDER BY
                                CASE
                                    WHEN resultado = 'Riesgo suicida agudo/inminente' THEN 1
                                    WHEN resultado = 'Riesgo suicida no agudo' THEN 2
                                    WHEN resultado = 'Sin riesgo' THEN 3
                                    ELSE 4
                                END,
                                {$asqExpr} DESC,
                                id DESC
                        ) as rn
                    ")
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'fecha_registro'));

                $asqCounts = DB::query()->fromSub($asqSub, 's')
                    ->where('rn', 1)
                    ->selectRaw('resultado, COUNT(*) as cantidad')
                    ->groupBy('resultado')
                    ->pluck('cantidad', 'resultado')
                    ->toArray();

                $rsa = (int)($asqCounts['Riesgo suicida agudo/inminente'] ?? 0);
                $rsna = (int)($asqCounts['Riesgo suicida no agudo'] ?? 0);
                $sinRiesgo = (int)($asqCounts['Sin riesgo'] ?? 0);
                $totalAsqUsers = $rsa + $rsna + $sinRiesgo;

                $analisis_asq = [
                    'rsa_si' => $rsa,
                    'rsa_no' => max(0, $totalAsqUsers - $rsa),
                    'rsna_si' => $rsa + $rsna,
                    'rsna_sin_riesgo' => $sinRiesgo,
                ];
            }

            // --------------------------------------------------------------------------------
            // Estado de Citas
            // --------------------------------------------------------------------------------
            $estado_citas = [
                ['estado' => 'Agendado', 'cantidad' => DB::table('citas')->where('estado', 1)->tap(fn($q) => $applyFilter($q, 'paciente_id'))->tap(fn($q) => $applyDateRange($q, 'fecha'))->count(), 'color' => '#3b82f6'],
                ['estado' => 'Atendido', 'cantidad' => DB::table('citas')->where('estado', 2)->tap(fn($q) => $applyFilter($q, 'paciente_id'))->tap(fn($q) => $applyDateRange($q, 'fecha'))->count(), 'color' => '#10b981'],
                ['estado' => 'Reagendado', 'cantidad' => DB::table('citas')->whereIn('estado', [5])->tap(fn($q) => $applyFilter($q, 'paciente_id'))->tap(fn($q) => $applyDateRange($q, 'fecha'))->count(), 'color' => '#f59e0b'],
                ['estado' => 'Cancelado', 'cantidad' => DB::table('citas')->where('estado', 4)->tap(fn($q) => $applyFilter($q, 'paciente_id'))->tap(fn($q) => $applyDateRange($q, 'fecha'))->count(), 'color' => '#6b7280'],
                ['estado' => 'No se presentó', 'cantidad' => DB::table('citas')->where('estado', 3)->tap(fn($q) => $applyFilter($q, 'paciente_id'))->tap(fn($q) => $applyDateRange($q, 'fecha'))->count(), 'color' => '#ef4444'],
            ];

            // --------------------------------------------------------------------------------
            // Tendencia Mensual
            // --------------------------------------------------------------------------------
            $tendencia_mensual = $this->getTendenciaMensual($filteredUserIds);

            // --------------------------------------------------------------------------------
            // Protocolos (Simulados por ahora)
            // --------------------------------------------------------------------------------
            // La tabla curso_abordaje no tiene columna 'estado', simulamos distribución
            $protocolos = [
                'en_curso' => round($protocolosActivos * 0.4),
                'completados' => round($protocolosActivos * 0.5),
                'pendientes' => round($protocolosActivos * 0.1),
                'total_intervenciones' => $protocolosActivos,
                'promedio_sesiones_paciente' => 4.2,
            ];

            // --------------------------------------------------------------------------------
            // Disponibilidad Horaria (Simulada por ahora)
            // --------------------------------------------------------------------------------
            $disponibilidad = [
                'horarios_disponibles' => 240,
                'horarios_ocupados' => 185,
                'terapeutas_activos' => 8,
                'tasa_ocupacion' => 77,
            ];

            // --------------------------------------------------------------------------------
            // Cantidad por Instituciones
            // --------------------------------------------------------------------------------
            if ($canUseTamizMaterialized && $tamizBase !== null) {
                $instituciones = (clone $tamizBase)
                    ->selectRaw('institucion as name, count(*) as value')
                    ->whereNotNull('institucion')
                    ->where('institucion', '<>', '')
                    ->groupBy('institucion')
                    ->orderByDesc('value')
                    ->limit(10)
                    ->get();
            } else {
                $padronInst = DB::table('serumista_equivalentes_remunerados');
                if ($departamento) $padronInst->where('DEPARTAMENTO', $departamento);
                if ($institucion) $padronInst->where('INSTITUCION', 'LIKE', '%' . $institucion . '%');
                if ($modalidad === 'REMUNERADO') $padronInst->where('MODALIDAD', 'REMUNERADOS');
                if ($modalidad === 'EQUIVALENTE') $padronInst->where('MODALIDAD', 'EQUIVALENTES');

                $instituciones = $padronInst
                    ->selectRaw('INSTITUCION as name, count(*) as value')
                    ->whereNotNull('INSTITUCION')
                    ->where('INSTITUCION', '<>', '')
                    ->groupBy('INSTITUCION')
                    ->orderByDesc('value')
                    ->limit(10)
                    ->get();
            }

            // --------------------------------------------------------------------------------
            // Alertas y Recomendaciones
            // --------------------------------------------------------------------------------
            $alertas = [
                [ 'tipo' => 'warning', 'mensaje' => ($analisis_asq['rsa_si'] ?? 0) . ' serumistas con RSA positivo requieren seguimiento prioritario' ],
                [ 'tipo' => 'danger', 'mensaje' => ($distribucion_phq[0]['cantidad'] ?? 0) . ' casos de riesgo alto en Depresión (PHQ) pendientes de atención especializada' ],
                [ 'tipo' => 'info', 'mensaje' => ($estado_citas[4]['cantidad'] ?? 0) . ' citas sin presentarse - revisar casos' ],
                [ 'tipo' => 'danger', 'mensaje' => ($distribucion_mbi[0]['cantidad'] ?? 0) . ' serumistas con Presencia de Burnout necesitan intervención inmediata' ],
            ];

            $totalElapsed = round((microtime(true) - $overallStart) * 1000, 2);
            \Illuminate\Support\Facades\Log::info("Dashboard: [FINAL] fetchDashboardData completado en {$totalElapsed}ms total");

            return [
                'estadisticas_generales' => $estadisticas_generales,
                'evaluaciones_por_tipo' => $evaluaciones_por_tipo,
                'total_por_concepto' => $total_por_concepto,
                'distribucion_sexo' => $distribucion_sexo,
                'distribucion_edad' => $distribucion_edad,
                'distribucion_phq' => $distribucion_phq,
                'distribucion_gad' => $distribucion_gad,
                'distribucion_audit' => $distribucion_audit,
                'distribucion_mbi' => $distribucion_mbi,
                'analisis_asq' => $analisis_asq,
                'estado_citas' => $estado_citas,
                'tendencia_mensual' => $tendencia_mensual,
                'protocolos' => $protocolos,
                'disponibilidad' => $disponibilidad,
                'alertas' => $alertas,
                'cantidad_por_instituciones' => $instituciones,
            ];

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Dashboard Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            // Re-lanzar la excepción para que se maneje fuera del caché
            throw $e;
        }
    }

    private function getTendenciaMensual($filteredUserIds = null)
    {
        $meses = [];
        $applyFilter = function($q, $userColumn = 'user_id') use ($filteredUserIds) {
            if ($filteredUserIds !== null) {
                $q->whereIn($userColumn, $filteredUserIds);
            }
        };

        // Últimos 6 meses
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $mesNombre = $date->format('M');
            $mesNum = $date->month;
            $year = $date->year;
            $inicioMes = Carbon::create($year, $mesNum, 1)->startOfDay();
            $finMes = Carbon::create($year, $mesNum, 1)->endOfMonth()->endOfDay();

            // Contar citas en este mes
            $citas = DB::table('citas')
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $mesNum)
                ->tap(fn($q) => $applyFilter($q, 'paciente_id'))
                ->count();

            // Contar evaluaciones
            $evaluaciones = 0;
            // asq5_responses.fecha_registro puede ser NVARCHAR (evitar error de conversión)
            $exprAsq = "COALESCE(
                TRY_CONVERT(datetime2, fecha_registro, 120),
                TRY_CONVERT(datetime2, fecha_registro, 126),
                TRY_CONVERT(datetime2, fecha_registro, 23),
                TRY_CONVERT(datetime2, fecha_registro, 103),
                TRY_CONVERT(datetime2, fecha_registro, 101),
                TRY_CONVERT(datetime2, fecha_registro, 112),
                TRY_CONVERT(datetime2, fecha_registro)
            )";
            $evaluaciones += DB::table('asq5_responses')
                ->tap(fn($q) => $applyFilter($q))
                ->whereRaw("{$exprAsq} BETWEEN ? AND ?", [$inicioMes->format('Y-m-d H:i:s'), $finMes->format('Y-m-d H:i:s')])
                ->count();
            $evaluaciones += DB::table('phq9_responses')->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->tap(fn($q) => $applyFilter($q))->count();
            $evaluaciones += DB::table('gad_responses')->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->tap(fn($q) => $applyFilter($q))->count();
            $evaluaciones += DB::table('mbi_responses')->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->tap(fn($q) => $applyFilter($q))->count();
            $evaluaciones += DB::table('audit_responses')->whereYear('fecha', $year)->whereMonth('fecha', $mesNum)->tap(fn($q) => $applyFilter($q))->count();

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

    /**
     * Asigna edad a rango: 23-25, 26-30, 31-35, 36-40, 41-45, 46-50, 51-55, 56-60.
     */
    private function bucketEdadRango($edad)
    {
        if ($edad === null || $edad === '') {
            return 'No definido';
        }
        $e = (int) $edad;
        if ($e < 23) return 'Menor de 23';
        if ($e <= 25) return '23-25';
        if ($e <= 30) return '26-30';
        if ($e <= 35) return '31-35';
        if ($e <= 40) return '36-40';
        if ($e <= 45) return '41-45';
        if ($e <= 50) return '46-50';
        if ($e <= 55) return '51-55';
        if ($e <= 60) return '56-60';
        return 'Mayor de 60';
    }

    /**
     * Distribución por grupo etáreo usando rangos 23-25, 26-30, ..., 56-60.
     * Consulta usuarios tamizados + Mat_Colegiado para edad real.
     */
    private function getDistribucionEdadFallback($filteredUserIds, $applyFilter, $applyDateRange, $applyDateRangeNvarchar, $totalTamizados)
    {
        $uAsq = DB::table('asq5_responses')->select('user_id')->distinct();
        $applyFilter($uAsq);
        $applyDateRangeNvarchar($uAsq, 'fecha_registro');
        $uPhq = DB::table('phq9_responses')->select('user_id')->distinct();
        $applyFilter($uPhq);
        $applyDateRange($uPhq, 'fecha');
        $uGad = DB::table('gad_responses')->select('user_id')->distinct();
        $applyFilter($uGad);
        $applyDateRange($uGad, 'fecha');
        $uMbi = DB::table('mbi_responses')->select('user_id')->distinct();
        $applyFilter($uMbi);
        $applyDateRange($uMbi, 'fecha');
        $uAud = DB::table('audit_responses')->select('user_id')->distinct();
        $applyFilter($uAud);
        $applyDateRange($uAud, 'fecha');
        $union = $uAsq->union($uPhq)->union($uGad)->union($uMbi)->union($uAud);
        $tamizadosSub = DB::query()->fromSub($union, 'tu')->select('user_id')->distinct();
        $edadExpr = "DATEDIFF(YEAR, mat.FechaNacimiento, GETDATE()) - CASE
            WHEN (MONTH(GETDATE())<MONTH(mat.FechaNacimiento)) OR (MONTH(GETDATE())=MONTH(mat.FechaNacimiento) AND DAY(GETDATE())<DAY(mat.FechaNacimiento))
            THEN 1 ELSE 0 END";
        $bucketCase = "CASE
            WHEN {$edadExpr} IS NULL OR mat.FechaNacimiento IS NULL THEN 'No definido'
            WHEN {$edadExpr} < 23 THEN 'Menor de 23'
            WHEN {$edadExpr} <= 25 THEN '23-25'
            WHEN {$edadExpr} <= 30 THEN '26-30'
            WHEN {$edadExpr} <= 35 THEN '31-35'
            WHEN {$edadExpr} <= 40 THEN '36-40'
            WHEN {$edadExpr} <= 45 THEN '41-45'
            WHEN {$edadExpr} <= 50 THEN '46-50'
            WHEN {$edadExpr} <= 55 THEN '51-55'
            WHEN {$edadExpr} <= 60 THEN '56-60'
            ELSE 'Mayor de 60'
        END";
        try {
            $rows = DB::query()
                ->fromSub($tamizadosSub, 't')
                ->join('usuarios as u', 't.user_id', '=', 'u.id')
                ->leftJoin(DB::raw('[CMP02].[db_cmp].[dbo].[Mat_Colegiado] as mat'), DB::raw('CAST(u.cmp AS VARCHAR(20))'), '=', DB::raw('CAST(mat.Colegiado_Id AS VARCHAR(20))'))
                ->where('u.estado', 1)
                ->selectRaw("{$bucketCase} as grupo_edad, COUNT(*) as cantidad")
                ->groupByRaw($bucketCase)
                ->pluck('cantidad', 'grupo_edad')
                ->toArray();
        } catch (\Throwable $e) {
            $t = max(1, $totalTamizados);
            return [
                ['grupo' => 'Menor de 23', 'cantidad' => (int)round($t * 0.05)],
                ['grupo' => '23-25', 'cantidad' => (int)round($t * 0.08)],
                ['grupo' => '26-30', 'cantidad' => (int)round($t * 0.18)],
                ['grupo' => '31-35', 'cantidad' => (int)round($t * 0.22)],
                ['grupo' => '36-40', 'cantidad' => (int)round($t * 0.20)],
                ['grupo' => '41-45', 'cantidad' => (int)round($t * 0.12)],
                ['grupo' => '46-50', 'cantidad' => (int)round($t * 0.08)],
                ['grupo' => '51-55', 'cantidad' => (int)round($t * 0.05)],
                ['grupo' => '56-60', 'cantidad' => (int)round($t * 0.02)],
                ['grupo' => 'Mayor de 60', 'cantidad' => (int)round($t * 0.00)],
            ];
        }
        $order = ['Menor de 23', '23-25', '26-30', '31-35', '36-40', '41-45', '46-50', '51-55', '56-60', 'Mayor de 60', 'No definido'];
        $result = [];
        foreach ($order as $g) {
            if (isset($rows[$g]) && $rows[$g] > 0) {
                $result[] = ['grupo' => $g, 'cantidad' => (int)$rows[$g]];
            }
        }
        return $result;
    }

    /**
     * Total por concepto en fallback: peor nivel por tamizado. Usa conteos de AUDIT como aproximación
     * (Total por Concepto requiere peor nivel cruzado; sin vista materializada usamos AUDIT como proxy).
     */
    private function getTotalPorConceptoFallback($filteredUserIds, $applyFilter, $applyDateRange, $applyDateRangeNvarchar)
    {
        $audSub = DB::table('audit_responses')
            ->selectRaw("user_id, riesgo, ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY fecha DESC) as rn")
            ->tap(fn ($q) => $applyFilter($q))
            ->tap(fn ($q) => $applyDateRange($q, 'fecha'));

        $audCase = "CASE
            WHEN riesgo IN ('Consumo problemático','Probable consumo problemático') THEN 'ALTO'
            WHEN riesgo = 'Consumo riesgoso' THEN 'MODERADO'
            WHEN riesgo IN ('Bajo','Riesgo bajo') THEN 'LEVE'
            WHEN riesgo IS NULL OR riesgo = '' THEN 'SIN REGISTRO'
            ELSE 'SIN REGISTRO'
        END";
        $conceptoCounts = DB::query()->fromSub($audSub, 'a')
            ->where('rn', 1)
            ->selectRaw("{$audCase} as concepto, COUNT(*) as cnt")
            ->groupByRaw($audCase)
            ->pluck('cnt', 'concepto')
            ->toArray();

        return [
            ['nivel' => '1.ALTO', 'cantidad' => (int)($conceptoCounts['ALTO'] ?? 0), 'color' => '#ef4444'],
            ['nivel' => '2.MODERADO', 'cantidad' => (int)($conceptoCounts['MODERADO'] ?? 0), 'color' => '#f59e0b'],
            ['nivel' => '3.LEVE', 'cantidad' => (int)($conceptoCounts['LEVE'] ?? 0), 'color' => '#10b981'],
            ['nivel' => '4.SIN RIESGO', 'cantidad' => (int)($conceptoCounts['SIN RIESGO'] ?? 0), 'color' => '#9ca3af'],
            ['nivel' => '5.SIN REGISTRO', 'cantidad' => (int)($conceptoCounts['SIN REGISTRO'] ?? 0), 'color' => '#6b7280'],
        ];
    }

    /**
     * Calcula el peor concepto (ALTO, MODERADO, LEVE, SIN RIESGO, SIN REGISTRO) para un tamizado
     * a partir de depresion, ansiedad, alcohol, burnout, riesgo_suicida_agudo, riesgo_suicida_no_agudo.
     */
    private function calcularConceptoTamizado($row)
    {
        $niveles = [];
        $d = isset($row->depresion) ? trim((string) $row->depresion) : '';
        $a = isset($row->ansiedad) ? trim((string) $row->ansiedad) : '';
        $al = isset($row->alcohol) ? trim((string) $row->alcohol) : '';
        $b = isset($row->burnout) ? trim((string) $row->burnout) : '';
        $rsa = isset($row->riesgo_suicida_agudo) ? trim((string) $row->riesgo_suicida_agudo) : '';
        $rsna = isset($row->riesgo_suicida_no_agudo) ? trim((string) $row->riesgo_suicida_no_agudo) : '';

        foreach ([$d, $a, $al, $b, $rsa, $rsna] as $v) {
            $v = strtolower($v);
            if ($v === '' || strpos($v, 'sin registro') !== false || strpos($v, 'sin registr') !== false) {
                $niveles[] = 5; // SIN REGISTRO
            } elseif (
                strpos($v, 'riesgo alto') !== false || strpos($v, 'consumo problemático') !== false
                || strpos($v, 'consumo problematico') !== false || strpos($v, 'presencia') !== false
                || $v === 'sí' || $v === 'si'
            ) {
                $niveles[] = 1; // ALTO
            } elseif (
                strpos($v, 'riesgo moderado') !== false || strpos($v, 'consumo riesgoso') !== false
                || (strpos($v, 'sí') !== false && strpos($v, 'riesgo') !== false)
            ) {
                $niveles[] = 2; // MODERADO
            } elseif (strpos($v, 'riesgo leve') !== false || strpos($v, 'riesgo bajo') !== false) {
                $niveles[] = 3; // LEVE
            } elseif (strpos($v, 'ausencia') !== false || strpos($v, 'sin riesgo') !== false) {
                $niveles[] = 4; // SIN RIESGO
            } else {
                $niveles[] = 5;
            }
        }
        $peor = count($niveles) > 0 ? min($niveles) : 5;
        $map = [1 => 'ALTO', 2 => 'MODERADO', 3 => 'LEVE', 4 => 'SIN RIESGO', 5 => 'SIN REGISTRO'];
        return $map[$peor] ?? 'SIN REGISTRO';
    }

    /**
     * Obtener conteos de tamizados (TOTAL, REMUNERADOS y EQUIVALENTES).
     * TOTAL = todos los usuarios (estado=1) con al menos una evaluación.
     * REMUNERADOS = tamizados con CMP en serumista_remunerados.
     * EQUIVALENTES = tamizados con CMP en serumista_equivalentes MODALIDAD=EQUIVALENTES, excluyendo remunerados.
     */
    private function getTamizadosCounts($filteredUserIds, $departamento, $institucion, $modalidad, $dateRange, $applyDateRange, $applyDateRangeNvarchar)
    {
        $uAsq = DB::table('asq5_responses')->select('user_id')->distinct()
            ->when($filteredUserIds !== null, fn ($q) => $q->whereIn('user_id', $filteredUserIds))
            ->when($dateRange !== null, fn ($q) => $applyDateRangeNvarchar($q, 'fecha_registro'));
        $uPhq = DB::table('phq9_responses')->select('user_id')->distinct()
            ->when($filteredUserIds !== null, fn ($q) => $q->whereIn('user_id', $filteredUserIds))
            ->when($dateRange !== null, fn ($q) => $applyDateRange($q, 'fecha'));
        $uGad = DB::table('gad_responses')->select('user_id')->distinct()
            ->when($filteredUserIds !== null, fn ($q) => $q->whereIn('user_id', $filteredUserIds))
            ->when($dateRange !== null, fn ($q) => $applyDateRange($q, 'fecha'));
        $uMbi = DB::table('mbi_responses')->select('user_id')->distinct()
            ->when($filteredUserIds !== null, fn ($q) => $q->whereIn('user_id', $filteredUserIds))
            ->when($dateRange !== null, fn ($q) => $applyDateRange($q, 'fecha'));
        $uAud = DB::table('audit_responses')->select('user_id')->distinct()
            ->when($filteredUserIds !== null, fn ($q) => $q->whereIn('user_id', $filteredUserIds))
            ->when($dateRange !== null, fn ($q) => $applyDateRange($q, 'fecha'));

        $union = $uAsq->union($uPhq)->union($uGad)->union($uMbi)->union($uAud);
        $tamizadosUsers = DB::query()->fromSub($union, 'tu')->select('user_id')->distinct();

        // Total tamizados: sin filtros de padrón = TODOS con evaluaciones (709); con filtros = tamizados filtrados
        $hasPopulationFilters = !empty($departamento) || !empty($institucion) || in_array($modalidad, ['REMUNERADO', 'EQUIVALENTE']);
        if ($hasPopulationFilters) {
            $tamizadosTotal = (int) (clone $tamizadosUsers)->count();
        } else {
            $uAsqAll = DB::table('asq5_responses')->select('user_id')->distinct()
                ->when($dateRange !== null, fn ($q) => $applyDateRangeNvarchar($q, 'fecha_registro'));
            $uPhqAll = DB::table('phq9_responses')->select('user_id')->distinct()
                ->when($dateRange !== null, fn ($q) => $applyDateRange($q, 'fecha'));
            $uGadAll = DB::table('gad_responses')->select('user_id')->distinct()
                ->when($dateRange !== null, fn ($q) => $applyDateRange($q, 'fecha'));
            $uMbiAll = DB::table('mbi_responses')->select('user_id')->distinct()
                ->when($dateRange !== null, fn ($q) => $applyDateRange($q, 'fecha'));
            $uAudAll = DB::table('audit_responses')->select('user_id')->distinct()
                ->when($dateRange !== null, fn ($q) => $applyDateRange($q, 'fecha'));
            $unionAll = $uAsqAll->union($uPhqAll)->union($uGadAll)->union($uMbiAll)->union($uAudAll);
            $tamizadosTotal = (int) DB::query()->fromSub($unionAll, 'tu')->select('user_id')->distinct()->count();
        }

        $baseJoin = DB::query()
            ->fromSub($tamizadosUsers, 't')
            ->join('usuarios as u', 't.user_id', '=', 'u.id')
            ->where('u.estado', 1);

        if ($modalidad === 'REMUNERADO') {
            $tamizadosRemunerados = (clone $baseJoin)
                ->join('serumista_remunerados as sr', DB::raw('CAST(u.cmp AS VARCHAR)'), '=', DB::raw('CAST(sr.CMP AS VARCHAR)'))
                ->when($departamento, fn ($q) => $q->where('sr.DEPARTAMENTO', $departamento))
                ->when($institucion, fn ($q) => $q->where('sr.INSTITUCION', 'LIKE', '%' . $institucion . '%'))
                ->count();
            return ['total' => $tamizadosTotal, 'remunerados' => $tamizadosRemunerados, 'equivalentes' => 0];
        }

        if ($modalidad === 'EQUIVALENTE') {
            $tamizadosEquivalentes = (clone $baseJoin)
                ->join('serumista_equivalentes_remunerados as se', DB::raw('CAST(u.cmp AS VARCHAR)'), '=', DB::raw('CAST(se.CMP AS VARCHAR)'))
                ->where('se.MODALIDAD', 'EQUIVALENTES')
                ->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('serumista_remunerados as sr2')
                        ->whereRaw('CAST(sr2.CMP AS VARCHAR(20)) = CAST(se.CMP AS VARCHAR(20))');
                })
                ->when($departamento, fn ($q) => $q->where('se.DEPARTAMENTO', $departamento))
                ->when($institucion, fn ($q) => $q->where('se.INSTITUCION', 'LIKE', '%' . $institucion . '%'))
                ->count();
            return ['total' => $tamizadosTotal, 'remunerados' => 0, 'equivalentes' => $tamizadosEquivalentes];
        }

        $tamizadosRemunerados = (int) ((clone $baseJoin)
            ->join('serumista_remunerados as sr', DB::raw('CAST(u.cmp AS VARCHAR)'), '=', DB::raw('CAST(sr.CMP AS VARCHAR)'))
            ->when($departamento, fn ($q) => $q->where('sr.DEPARTAMENTO', $departamento))
            ->when($institucion, fn ($q) => $q->where('sr.INSTITUCION', 'LIKE', '%' . $institucion . '%'))
            ->selectRaw('COUNT(DISTINCT t.user_id) as cnt')
            ->value('cnt') ?? 0);

        $tamizadosEquivalentes = (int) ((clone $baseJoin)
            ->join('serumista_equivalentes_remunerados as se', DB::raw('CAST(u.cmp AS VARCHAR)'), '=', DB::raw('CAST(se.CMP AS VARCHAR)'))
            ->where('se.MODALIDAD', 'EQUIVALENTES')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('serumista_remunerados as sr2')
                    ->whereRaw('CAST(sr2.CMP AS VARCHAR(20)) = CAST(se.CMP AS VARCHAR(20))');
            })
            ->when($departamento, fn ($q) => $q->where('se.DEPARTAMENTO', $departamento))
            ->when($institucion, fn ($q) => $q->where('se.INSTITUCION', 'LIKE', '%' . $institucion . '%'))
            ->selectRaw('COUNT(DISTINCT t.user_id) as cnt')
            ->value('cnt') ?? 0);

        return ['total' => $tamizadosTotal, 'remunerados' => $tamizadosRemunerados, 'equivalentes' => $tamizadosEquivalentes];
    }

    /**
     * Obtener conteo de pacientes de alto riesgo por entidad (misma lógica que DerivacionController)
     * ESSALUD: usuarios en serumista_remunerados O no en serumista_equivalentes (incluye todos los tamizados/derivados)
     * MINSA: usuarios en serumista_equivalentes excluyendo remunerados
     */
    private function getHighRiskCountByEntity($entidad, $filteredUserIds = null)
    {
        $tableName = $entidad === 'MINSA' ? 'serumista_equivalentes_remunerados' : 'serumista_remunerados';

        // Misma base que DerivacionController::getBaseQuery: partir desde usuarios
        $query = DB::table('usuarios')
            ->leftJoin($tableName, DB::raw('CAST(usuarios.cmp AS VARCHAR)'), '=', DB::raw("CAST({$tableName}.CMP AS VARCHAR)"))
            ->where('usuarios.estado', 1);

        if ($entidad === 'ESSALUD') {
            $query->where(function ($q) use ($tableName) {
                $q->whereNotNull("{$tableName}.CMP")
                    ->orWhereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('serumista_equivalentes_remunerados')
                            ->whereRaw('CAST(serumista_equivalentes_remunerados.CMP AS VARCHAR) = CAST(usuarios.cmp AS VARCHAR)');
                    });
            });
        } elseif ($entidad === 'MINSA') {
            $query->whereNotNull("{$tableName}.CMP")
                ->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('serumista_remunerados')
                        ->whereRaw('CAST(serumista_remunerados.CMP AS VARCHAR) = CAST(usuarios.cmp AS VARCHAR)');
                });
        }

        // Aplicar filtro de usuarios si existe (Dashboard con departamento/institución/modalidad)
        if ($filteredUserIds !== null) {
            $query->whereIn('usuarios.id', $filteredUserIds);
        }

        // Aplicar filtro de alto riesgo (misma lógica que DerivacionController::applyHighRiskFilter)
        $query->where(function($q) {
            // PHQ9 - Riesgo Alto
            $q->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('phq9_responses')
                    ->whereColumn('phq9_responses.user_id', 'usuarios.id')
                    ->where('riesgo', 'Riesgo alto')
                    ->whereRaw('id_encuesta = (SELECT MAX(id_encuesta) FROM phq9_responses r2 WHERE r2.user_id = phq9_responses.user_id)');
            })
            // GAD - Riesgo Alto
            ->orWhereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('gad_responses')
                    ->whereColumn('gad_responses.user_id', 'usuarios.id')
                    ->where('riesgo', 'Riesgo alto')
                    ->whereRaw('id_encuesta = (SELECT MAX(id_encuesta) FROM gad_responses r2 WHERE r2.user_id = gad_responses.user_id)');
            })
            // MBI - Presencia de Burnout
            ->orWhereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('mbi_responses')
                    ->whereColumn('mbi_responses.user_id', 'usuarios.id')
                    ->where(function($w) {
                        $w->where('riesgoCE', 'Presencia de burnout')
                          ->orWhere('riesgoDP', 'Presencia de burnout')
                          ->orWhere('riesgoRP', 'Presencia de burnout');
                    })
                    ->whereRaw('id_encuesta = (SELECT MAX(id_encuesta) FROM mbi_responses r2 WHERE r2.user_id = mbi_responses.user_id)');
            })
            // AUDIT - Consumo problemático, Probable consumo problemático, Dependencia (igual que DerivacionController)
            ->orWhereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('audit_responses')
                    ->whereColumn('audit_responses.user_id', 'usuarios.id')
                    ->whereIn('riesgo', ['Consumo problemático', 'Probable consumo problemático', 'Dependencia'])
                    ->whereRaw('id_encuesta = (SELECT MAX(id_encuesta) FROM audit_responses r2 WHERE r2.user_id = audit_responses.user_id)');
            })
            // ASQ - Solo Riesgo suicida agudo/inminente (igual que DerivacionController)
            ->orWhereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('asq5_responses')
                    ->whereColumn('asq5_responses.user_id', 'usuarios.id')
                    ->where('resultado', 'Riesgo suicida agudo/inminente')
                    ->whereRaw('id = (SELECT MAX(id) FROM asq5_responses r2 WHERE r2.user_id = asq5_responses.user_id)');
            })
            // Derivados Manualmente (desde atención) - tabla derivados
            ->orWhereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('derivados')
                    ->whereColumn('derivados.paciente_id', 'usuarios.id');
            });
        });

        return $query->selectRaw('COUNT(DISTINCT usuarios.id) as total')
            ->value('total') ?? 0;
    }

    /**
     * Lista de tamizados no clasificados (ni Remunerados ni Equivalentes).
     * GET /api/dashboard/tamizados-no-clasificados?format=json|xlsx
     */
    public function tamizadosNoClasificados(Request $request)
    {
        try {
            $uAsq = DB::table('asq5_responses')->select('user_id')->distinct();
            $uPhq = DB::table('phq9_responses')->select('user_id')->distinct();
            $uGad = DB::table('gad_responses')->select('user_id')->distinct();
            $uMbi = DB::table('mbi_responses')->select('user_id')->distinct();
            $uAud = DB::table('audit_responses')->select('user_id')->distinct();
            $union = $uAsq->union($uPhq)->union($uGad)->union($uMbi)->union($uAud);
            $tamizadosSub = DB::query()->fromSub($union, 'tu')->select('user_id')->distinct();

            $query = DB::query()
                ->fromSub($tamizadosSub, 't')
                ->join('usuarios as u', 't.user_id', '=', 'u.id')
                ->where('u.estado', 1)
                ->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('serumista_remunerados as sr')
                        ->whereRaw('CAST(u.cmp AS VARCHAR) = CAST(sr.CMP AS VARCHAR)');
                })
                ->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('serumista_equivalentes_remunerados as se')
                        ->whereRaw('CAST(u.cmp AS VARCHAR) = CAST(se.CMP AS VARCHAR)')
                        ->where('se.MODALIDAD', 'EQUIVALENTES')
                        ->whereNotExists(function ($s2) {
                            $s2->select(DB::raw(1))
                                ->from('serumista_remunerados as sr2')
                                ->whereRaw('CAST(sr2.CMP AS VARCHAR) = CAST(se.CMP AS VARCHAR)');
                        });
                })
                ->select([
                    'u.id',
                    'u.nombre_completo',
                    'u.cmp',
                    'u.nombre_usuario as dni',
                    'u.telefono',
                ])
                ->orderBy('u.nombre_completo');

            $lista = $query->get();

            $format = $request->query('format', 'json');
            if (strtolower($format) === 'xlsx') {
                return $this->exportTamizadosNoClasificadosExcel($lista);
            }
            return response()->json([
                'total' => $lista->count(),
                'data' => $lista,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Dashboard tamizadosNoClasificados error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function exportTamizadosNoClasificadosExcel($lista)
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Tamizados No Clasificados');

            $headers = ['N°', 'Nombre completo', 'CMP', 'DNI', 'Teléfono', '¿Remunerado o Equivalente?'];
            foreach ($headers as $i => $h) {
                $sheet->setCellValue([$i + 1, 1], $h);
            }
            $sheet->getStyle('A1:F1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F81BD']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $row = 2;
            foreach ($lista as $idx => $r) {
                $sheet->setCellValue([1, $row], $idx + 1);
                $sheet->setCellValue([2, $row], $r->nombre_completo ?? '');
                $sheet->setCellValue([3, $row], $r->cmp ?? '');
                $sheet->setCellValue([4, $row], $r->dni ?? '');
                $sheet->setCellValue([5, $row], $r->telefono ?? '');
                $sheet->setCellValue([6, $row], '');
                $row++;
            }

            foreach (range('A', 'F') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $filename = 'Tamizados_No_Clasificados_' . date('Y-m-d_His') . '.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), 'excel_');
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            return response()->download($tempFile, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('exportTamizadosNoClasificadosExcel error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}

