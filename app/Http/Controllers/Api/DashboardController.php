<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

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
        // Verificación temprana de autenticación - responder rápido si no está autenticado
        if (!$request->user()) {
            return response()->json([
                'error' => 'No autenticado',
                'message' => 'El token de autenticación es inválido o ha expirado'
            ], 401);
        }

        try {
            // ====================================================================================
            // 1. FILTRADO DE POBLACIÓN (Usuarios/Serumistas)
            // ====================================================================================
            $departamento = $request->input('departamento'); // DEPARTAMENTO (select "Consejo Regional")
            $institucion = $request->input('institucion');
            $modalidad = $request->input('modalidad');
            $idProceso = $request->input('id_proceso'); // CORTE (envía id_proceso)
            $corteLegacy = $request->input('corte'); // compatibilidad: antes enviaban 2025-I

            $hasPopulationFilters = ($departamento || $institucion || $modalidad);
            $filteredUserIds = null; // Subquery de IDs (usuarios.id) pertenecientes al padrón + filtros
            $totalSerumistas = 0;    // Padrón (serumista_equivalentes_remunerados)

            // Resolver rango de fechas por id_proceso (o corte legacy)
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

            // Padrón (incluye REMUNERADOS y EQUIVALENTES)
            $padronBase = DB::table('serumista_equivalentes_remunerados');
            if ($departamento) $padronBase->where('DEPARTAMENTO', $departamento);
            if ($institucion) $padronBase->where('INSTITUCION', 'LIKE', '%' . $institucion . '%');

            if ($modalidad) {
                if ($modalidad === 'REMUNERADO') $padronBase->where('MODALIDAD', 'REMUNERADOS');
                if ($modalidad === 'EQUIVALENTE') $padronBase->where('MODALIDAD', 'EQUIVALENTES');
            }

            $totalSerumistas = (clone $padronBase)->count();

            // IDs de usuarios registrados que pertenecen al padrón (base para métricas de médicos)
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

            // Determinar si podemos usar la vista/tabla materializada del dashboard.
            // En local puede fallar por dependencias a servidores/BD externos (ej. CMP02).
            $canUseTamizMaterialized = false;
            try {
                DB::table('dashboard_total_medicos_tamizaje')->select('user_id')->limit(1)->get();
                $canUseTamizMaterialized = true;
            } catch (\Throwable $e) {
                $canUseTamizMaterialized = false;
            }

            // ====================================================================================
            // 2. OBTENCIÓN DE MÉTRICAS (Con Filtros Aplicados)
            // ====================================================================================

            $tamizBase = null;
            if ($canUseTamizMaterialized) {
                // Evaluaciones por tipo (médicos) usando tabla/vista materializada del dashboard (1 fila por usuario)
                $tamizBase = DB::table('dashboard_total_medicos_tamizaje');
                if ($departamento) $tamizBase->where('departamento', $departamento);
                if ($institucion) $tamizBase->where('institucion', 'LIKE', '%' . $institucion . '%');
                if ($modalidad === 'REMUNERADO') $tamizBase->where('modalidad', 'REMUNERADOS');
                if ($modalidad === 'EQUIVALENTE') $tamizBase->where('modalidad', 'EQUIVALENTES');

                // Aplicar corte: cualquier evaluación dentro del rango
                if ($dateRange !== null) {
                    $tamizBase->where(function ($q) use ($dateRange) {
                        $start = $dateRange['start']->format('Y-m-d H:i:s');
                        $end = $dateRange['end']->format('Y-m-d H:i:s');
                        $q->whereBetween('fecha_suicidio_agudo', [$start, $end])
                            ->orWhereBetween('fecha_suicidio_no_agudo', [$start, $end])
                            // fechas en varchar(19) estilo 120
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_depresion, 120) BETWEEN ? AND ?", [$start, $end])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_ansiedad, 120) BETWEEN ? AND ?", [$start, $end])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_alcohol, 120) BETWEEN ? AND ?", [$start, $end])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_burnout, 120) BETWEEN ? AND ?", [$start, $end]);
                    });
                }

                // Conteo por tipo (usuarios con registro en ese tamizaje)
                $totalAsq = (clone $tamizBase)
                    ->where(function ($q) {
                        $q->where('riesgo_suicida_no_agudo', '!=', 'Sin Registro')
                          ->orWhereNotNull('fecha_suicidio_agudo')
                          ->orWhereNotNull('fecha_suicidio_no_agudo');
                    })
                    ->count();

                $totalPhq = (clone $tamizBase)->where('depresion', '!=', 'Sin registro')->count();
                $totalGad = (clone $tamizBase)->where('ansiedad', '!=', 'Sin registro')->count();
                $totalAudit = (clone $tamizBase)->where('alcohol', '!=', 'Sin registro')->count();
                $totalMbi = (clone $tamizBase)->where('burnout', '!=', 'Sin registro')->count();
            } else {
                // Fallback: contar por USUARIO (distinct) desde tablas base (sin dependencias externas)
                $totalAsq = DB::table('asq5_responses')
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'fecha_registro'))
                    ->distinct('user_id')
                    ->count('user_id');
                $totalPhq = DB::table('phq9_responses')
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                    ->distinct('user_id')
                    ->count('user_id');
                $totalGad = DB::table('gad_responses')
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                    ->distinct('user_id')
                    ->count('user_id');
                $totalMbi = DB::table('mbi_responses')
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                    ->distinct('user_id')
                    ->count('user_id');
                $totalAudit = DB::table('audit_responses')
                    ->tap(fn ($q) => $applyFilter($q))
                    ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                    ->distinct('user_id')
                    ->count('user_id');
            }

            $totalEvaluaciones = $totalAsq + $totalPhq + $totalGad + $totalMbi + $totalAudit;

            // Citas
            $totalCitas = DB::table('citas')
                ->tap(fn ($q) => $applyFilter($q, 'paciente_id'))
                ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                ->count();
            $citasAtendidas = DB::table('citas')
                ->where('estado', 2)
                ->tap(fn ($q) => $applyFilter($q, 'paciente_id'))
                ->tap(fn ($q) => $applyDateRange($q, 'fecha'))
                ->count();

            // Protocolos
            $protocolosActivos = DB::table('curso_abordaje')->count();

            // ====================================================================================
            // 3. ESTADÍSTICAS DETALLADAS POR MODALIDAD - USANDO VISTAS DE LA BASE DE DATOS
            // ====================================================================================

            // Total de Usuarios por Modalidad (padrón completo)
            $padronBreakdown = DB::table('serumista_equivalentes_remunerados');
            if ($departamento) $padronBreakdown->where('DEPARTAMENTO', $departamento);
            if ($institucion) $padronBreakdown->where('INSTITUCION', 'LIKE', '%' . $institucion . '%');
            if ($modalidad === 'REMUNERADO') $padronBreakdown->where('MODALIDAD', 'REMUNERADOS');
            if ($modalidad === 'EQUIVALENTE') $padronBreakdown->where('MODALIDAD', 'EQUIVALENTES');

            $totalRemunerados = (clone $padronBreakdown)->where('MODALIDAD', 'REMUNERADOS')->count();
            $totalEquivalentes = (clone $padronBreakdown)->where('MODALIDAD', 'EQUIVALENTES')->count();

            // Usuarios que accedieron (cálculo directo, consistente con filtros)
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

            // Tamizados por Modalidad
            if ($canUseTamizMaterialized) {
                $tamizCountBase = DB::table('dashboard_total_medicos_tamizaje');
                if ($departamento) $tamizCountBase->where('departamento', $departamento);
                if ($institucion) $tamizCountBase->where('institucion', 'LIKE', '%' . $institucion . '%');
                if ($modalidad === 'REMUNERADO') $tamizCountBase->where('modalidad', 'REMUNERADOS');
                if ($modalidad === 'EQUIVALENTE') $tamizCountBase->where('modalidad', 'EQUIVALENTES');

                if ($dateRange !== null) {
                    $tamizCountBase->where(function ($q) use ($dateRange) {
                        $start = $dateRange['start']->format('Y-m-d H:i:s');
                        $end = $dateRange['end']->format('Y-m-d H:i:s');
                        $q->whereBetween('fecha_suicidio_agudo', [$start, $end])
                            ->orWhereBetween('fecha_suicidio_no_agudo', [$start, $end])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_depresion, 120) BETWEEN ? AND ?", [$start, $end])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_ansiedad, 120) BETWEEN ? AND ?", [$start, $end])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_alcohol, 120) BETWEEN ? AND ?", [$start, $end])
                            ->orWhereRaw("TRY_CONVERT(datetime2, fecha_burnout, 120) BETWEEN ? AND ?", [$start, $end]);
                    });
                }

                $tamizadosRemunerados = (clone $tamizCountBase)->where('modalidad', 'REMUNERADOS')->count();
                $tamizadosEquivalentes = (clone $tamizCountBase)->where('modalidad', 'EQUIVALENTES')->count();
            } else {
                // Fallback: usuarios con al menos un tamizaje (union de tablas), luego clasificar por modalidad del padrón
                $uAsq = DB::table('asq5_responses')->select('user_id')->distinct()->tap(fn ($q) => $applyFilter($q))->tap(fn ($q) => $applyDateRangeNvarchar($q, 'fecha_registro'));
                $uPhq = DB::table('phq9_responses')->select('user_id')->distinct()->tap(fn ($q) => $applyFilter($q))->tap(fn ($q) => $applyDateRange($q, 'fecha'));
                $uGad = DB::table('gad_responses')->select('user_id')->distinct()->tap(fn ($q) => $applyFilter($q))->tap(fn ($q) => $applyDateRange($q, 'fecha'));
                $uMbi = DB::table('mbi_responses')->select('user_id')->distinct()->tap(fn ($q) => $applyFilter($q))->tap(fn ($q) => $applyDateRange($q, 'fecha'));
                $uAud = DB::table('audit_responses')->select('user_id')->distinct()->tap(fn ($q) => $applyFilter($q))->tap(fn ($q) => $applyDateRange($q, 'fecha'));

                $union = $uAsq->union($uPhq)->union($uGad)->union($uMbi)->union($uAud);

                $tamizadosUsers = DB::query()->fromSub($union, 'tu')->select('user_id')->distinct();

                $byModalidad = DB::query()
                    ->fromSub($tamizadosUsers, 't')
                    ->join('usuarios as u', 't.user_id', '=', 'u.id')
                    ->join('serumista_equivalentes_remunerados as se', DB::raw('CAST(u.cmp AS VARCHAR)'), '=', DB::raw('CAST(se.CMP AS VARCHAR)'))
                    ->selectRaw('se.MODALIDAD as modalidad, COUNT(DISTINCT t.user_id) as count')
                    ->groupBy('se.MODALIDAD')
                    ->pluck('count', 'modalidad')
                    ->toArray();

                $tamizadosRemunerados = (int)($byModalidad['REMUNERADOS'] ?? 0);
                $tamizadosEquivalentes = (int)($byModalidad['EQUIVALENTES'] ?? 0);
            }

            // Citas registradas (total de citas)
            $totalCitasRegistradas = $totalCitas;

            // Citas de Intervención Breve atendidas (citas con estado 2 que tienen sesión)
            $citasIntervencionBreveAtendidas = DB::table('citas')
                ->join('citas_finalizados', 'citas.id', '=', 'citas_finalizados.cita_id')
                ->where('citas.estado', 2)
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('citas.paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRange($q, 'citas.fecha'))
                ->count();

            // ====================================================================================
            // 4. DERIVACIONES
            // ====================================================================================

            // Total de casos derivados
            $totalCasosDerivados = DB::table('derivados')
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'fecha'))
                ->distinct('paciente_id')
                ->count('paciente_id');

            // Derivados desde Tamizaje
            $derivadosTamizaje = DB::table('derivados')
                ->where('tipo', 'A') // A = automático (tamizaje/criterio riesgo)
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'fecha'))
                ->distinct('paciente_id')
                ->count('paciente_id');

            // Derivados desde Intervención Breve
            $derivadosIntervencionBreve = DB::table('derivados')
                ->where('tipo', 'M') // M = manual (desde atención)
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'fecha'))
                ->distinct('paciente_id')
                ->count('paciente_id');

            // Derivaciones por Institución (ESSALUD y MINSA)
            // Usar serumista_equivalentes_remunerados para ser consistente con las vistas
            $derivacionesEssaludQuery = DB::table('derivados')
                ->join('usuarios', 'derivados.paciente_id', '=', 'usuarios.id')
                ->join('serumista_equivalentes_remunerados', DB::raw('CAST(usuarios.cmp AS VARCHAR)'), '=', DB::raw('CAST(serumista_equivalentes_remunerados.CMP AS VARCHAR)'))
                ->where('serumista_equivalentes_remunerados.INSTITUCION', 'LIKE', '%ESSALUD%')
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('derivados.paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'derivados.fecha'))
                ->select('derivados.id')
                ->distinct();

            $derivacionesEssalud = DB::table(DB::raw("({$derivacionesEssaludQuery->toSql()}) as sub"))
                ->mergeBindings($derivacionesEssaludQuery)
                ->count();

            $derivacionesMinsaQuery = DB::table('derivados')
                ->join('usuarios', 'derivados.paciente_id', '=', 'usuarios.id')
                ->join('serumista_equivalentes_remunerados', DB::raw('CAST(usuarios.cmp AS VARCHAR)'), '=', DB::raw('CAST(serumista_equivalentes_remunerados.CMP AS VARCHAR)'))
                ->where('serumista_equivalentes_remunerados.INSTITUCION', 'LIKE', '%MINSA%')
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('derivados.paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'derivados.fecha'))
                ->select('derivados.id')
                ->distinct();

            $derivacionesMinsa = DB::table(DB::raw("({$derivacionesMinsaQuery->toSql()}) as sub"))
                ->mergeBindings($derivacionesMinsaQuery)
                ->count();

            // Derivaciones atendidas (tabla derivaciones_atencion)
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

            // Derivaciones desde Tamizaje por tipo de evaluación
            $derivacionesAsqQuery = DB::table('derivados')
                ->join('asq5_responses', 'derivados.paciente_id', '=', 'asq5_responses.user_id')
                ->where('derivados.tipo', 'A')
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('derivados.paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'derivados.fecha'))
                ->select('derivados.paciente_id')
                ->distinct();

            $derivacionesAsq = DB::table(DB::raw("({$derivacionesAsqQuery->toSql()}) as sub"))
                ->mergeBindings($derivacionesAsqQuery)
                ->count();

            $derivacionesPhqQuery = DB::table('derivados')
                ->join('phq9_responses', 'derivados.paciente_id', '=', 'phq9_responses.user_id')
                ->where('derivados.tipo', 'A')
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('derivados.paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'derivados.fecha'))
                ->select('derivados.paciente_id')
                ->distinct();

            $derivacionesPhq = DB::table(DB::raw("({$derivacionesPhqQuery->toSql()}) as sub"))
                ->mergeBindings($derivacionesPhqQuery)
                ->count();

            $derivacionesGadQuery = DB::table('derivados')
                ->join('gad_responses', 'derivados.paciente_id', '=', 'gad_responses.user_id')
                ->where('derivados.tipo', 'A')
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('derivados.paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'derivados.fecha'))
                ->select('derivados.paciente_id')
                ->distinct();

            $derivacionesGad = DB::table(DB::raw("({$derivacionesGadQuery->toSql()}) as sub"))
                ->mergeBindings($derivacionesGadQuery)
                ->count();

            $derivacionesMbiQuery = DB::table('derivados')
                ->join('mbi_responses', 'derivados.paciente_id', '=', 'mbi_responses.user_id')
                ->where('derivados.tipo', 'A')
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('derivados.paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'derivados.fecha'))
                ->select('derivados.paciente_id')
                ->distinct();

            $derivacionesMbi = DB::table(DB::raw("({$derivacionesMbiQuery->toSql()}) as sub"))
                ->mergeBindings($derivacionesMbiQuery)
                ->count();

            $derivacionesAuditQuery = DB::table('derivados')
                ->join('audit_responses', 'derivados.paciente_id', '=', 'audit_responses.user_id')
                ->where('derivados.tipo', 'A')
                ->when($filteredUserIds, function($q) use ($filteredUserIds) {
                    $q->whereIn('derivados.paciente_id', $filteredUserIds);
                })
                ->tap(fn ($q) => $applyDateRangeNvarchar($q, 'derivados.fecha'))
                ->select('derivados.paciente_id')
                ->distinct();

            $derivacionesAudit = DB::table(DB::raw("({$derivacionesAuditQuery->toSql()}) as sub"))
                ->mergeBindings($derivacionesAuditQuery)
                ->count();

            // Estadísticas Generales (Top Cards)
            $estadisticas_generales = [
                'total_serumistas' => $totalSerumistas,
                'total_remunerados' => $totalRemunerados,
                'total_equivalentes' => $totalEquivalentes,
                'accedieron_total' => $accedieronRemunerados + $accedieronEquivalentes,
                'accedieron_remunerados' => $accedieronRemunerados,
                'accedieron_equivalentes' => $accedieronEquivalentes,
                'tamizados_total' => $tamizadosRemunerados + $tamizadosEquivalentes,
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
                // --------------------------------------------------------------------------------
                // Distribución por Sexo (tamizados) - tabla/vista materializada
                // --------------------------------------------------------------------------------
                $sexoCounts = (clone $tamizBase)
                    ->selectRaw('FlagMasculino as sexo, COUNT(*) as count')
                    ->whereIn('FlagMasculino', ['Masculino', 'Femenino'])
                    ->groupBy('FlagMasculino')
                    ->pluck('count', 'sexo')
                    ->toArray();

                $distribucion_sexo = [
                    ['name' => 'Masculino', 'value' => (int)($sexoCounts['Masculino'] ?? 0), 'color' => '#3b82f6'],
                    ['name' => 'Femenino', 'value' => (int)($sexoCounts['Femenino'] ?? 0), 'color' => '#ec4899'],
                ];

                // --------------------------------------------------------------------------------
                // Distribución por Grupo Etáreo (tamizados)
                // --------------------------------------------------------------------------------
                $edadCounts = (clone $tamizBase)
                    ->selectRaw('grupo_etareo as grupo, COUNT(*) as cantidad')
                    ->whereNotNull('grupo_etareo')
                    ->groupBy('grupo_etareo')
                    ->pluck('cantidad', 'grupo')
                    ->toArray();

                $edadOrder = ['18-29 años', '30-59 años', '60-64 años', '65-69 años', '70 años a más', 'Menor de 18 años'];
                $distribucion_edad = [];
                foreach ($edadOrder as $g) {
                    if (isset($edadCounts[$g])) $distribucion_edad[] = ['grupo' => $g, 'cantidad' => (int)$edadCounts[$g]];
                }

                // --------------------------------------------------------------------------------
                // Gráficos de Riesgo (tamizados) - tabla/vista materializada
                // --------------------------------------------------------------------------------
                $phqCounts = (clone $tamizBase)->selectRaw('depresion as nivel, COUNT(*) as cantidad')->groupBy('depresion')->pluck('cantidad', 'nivel')->toArray();
                $distribucion_phq = [
                    ['nivel' => 'Riesgo alto', 'cantidad' => (int)($phqCounts['Riesgo alto'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Riesgo moderado', 'cantidad' => (int)($phqCounts['Riesgo moderado'] ?? 0), 'color' => '#f97316'],
                    ['nivel' => 'Riesgo leve', 'cantidad' => (int)($phqCounts['Riesgo leve'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => 'Sin riesgo', 'cantidad' => (int)($phqCounts['Sin riesgo'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($phqCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                $gadCounts = (clone $tamizBase)->selectRaw('ansiedad as nivel, COUNT(*) as cantidad')->groupBy('ansiedad')->pluck('cantidad', 'nivel')->toArray();
                $distribucion_gad = [
                    ['nivel' => 'Riesgo alto', 'cantidad' => (int)($gadCounts['Riesgo alto'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Riesgo moderado', 'cantidad' => (int)($gadCounts['Riesgo moderado'] ?? 0), 'color' => '#f97316'],
                    ['nivel' => 'Riesgo leve', 'cantidad' => (int)($gadCounts['Riesgo leve'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => 'Sin riesgo', 'cantidad' => (int)($gadCounts['Sin riesgo'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($gadCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                $auditCounts = (clone $tamizBase)->selectRaw('alcohol as nivel, COUNT(*) as cantidad')->groupBy('alcohol')->pluck('cantidad', 'nivel')->toArray();
                $distribucion_audit = [
                    ['nivel' => 'Consumo problemático', 'cantidad' => (int)($auditCounts['Consumo problemático'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Consumo riesgoso', 'cantidad' => (int)($auditCounts['Consumo riesgoso'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => 'Riesgo bajo', 'cantidad' => (int)($auditCounts['Riesgo bajo'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($auditCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                $mbiCounts = (clone $tamizBase)->selectRaw('burnout as nivel, COUNT(*) as cantidad')->groupBy('burnout')->pluck('cantidad', 'nivel')->toArray();
                $distribucion_mbi = [
                    ['nivel' => 'Presencia', 'cantidad' => (int)($mbiCounts['Presencia'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => 'Ausencia', 'cantidad' => (int)($mbiCounts['Ausencia'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => 'Sin registro', 'cantidad' => (int)($mbiCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                $total_por_concepto = [
                    ['nivel' => '1.ALTO', 'cantidad' => (int)($auditCounts['Consumo problemático'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => '2.MODERADO', 'cantidad' => (int)($auditCounts['Consumo riesgoso'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => '3.LEVE', 'cantidad' => (int)($auditCounts['Riesgo bajo'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => '4.SIN RIESGO', 'cantidad' => 0, 'color' => '#9ca3af'],
                    ['nivel' => '5.SIN REGISTRO', 'cantidad' => (int)($auditCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

                $analisis_asq = [
                    'rsa_si' => (clone $tamizBase)->where('riesgo_suicida_agudo', 'Sí')->count(),
                    'rsa_no' => (clone $tamizBase)->where('riesgo_suicida_agudo', 'No')->count(),
                    'rsna_si' => (clone $tamizBase)->where('riesgo_suicida_no_agudo', 'Sí')->count(),
                    'rsna_sin_riesgo' => (clone $tamizBase)->where('riesgo_suicida_no_agudo', 'Sin riesgo')->count(),
                ];
            } else {
                // --------------------------------------------------------------------------------
                // Fallback DEV: sin CMP02 -> usar tablas base + lógica de "último/prioridad por usuario"
                // --------------------------------------------------------------------------------
                // Sexo (desde usuarios)
                $sexoCase = "
                    CASE
                        WHEN TRY_CONVERT(int, sexo) = 1 OR UPPER(LTRIM(RTRIM(CAST(sexo as varchar(10))))) = 'M' THEN 'M'
                        WHEN TRY_CONVERT(int, sexo) = 0 OR UPPER(LTRIM(RTRIM(CAST(sexo as varchar(10))))) = 'F' THEN 'F'
                        ELSE NULL
                    END
                ";
                $sexoRows = DB::table('usuarios')
                    ->selectRaw("{$sexoCase} as sexo_norm, COUNT(*) as count")
                    ->whereRaw("{$sexoCase} IS NOT NULL")
                    ->when($filteredUserIds !== null, fn ($q) => $q->whereIn('id', $filteredUserIds))
                    ->groupByRaw($sexoCase)
                    ->pluck('count', 'sexo_norm')
                    ->toArray();

                $distribucion_sexo = [
                    ['name' => 'Masculino', 'value' => (int)($sexoRows['M'] ?? 0), 'color' => '#3b82f6'],
                    ['name' => 'Femenino', 'value' => (int)($sexoRows['F'] ?? 0), 'color' => '#ec4899'],
                ];

                // Edad (simulada en DEV)
                $distribucion_edad = [
                    ['grupo' => '18-24', 'cantidad' => round($totalSerumistas * 0.15)],
                    ['grupo' => '25-34', 'cantidad' => round($totalSerumistas * 0.45)],
                    ['grupo' => '35-44', 'cantidad' => round($totalSerumistas * 0.25)],
                    ['grupo' => '45+', 'cantidad' => round($totalSerumistas * 0.15)],
                ];

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

                // Total por concepto (según ALCOHOL)
                $total_por_concepto = [
                    ['nivel' => '1.ALTO', 'cantidad' => (int)($auditCounts['Consumo problemático'] ?? 0), 'color' => '#ef4444'],
                    ['nivel' => '2.MODERADO', 'cantidad' => (int)($auditCounts['Consumo riesgoso'] ?? 0), 'color' => '#f59e0b'],
                    ['nivel' => '3.LEVE', 'cantidad' => (int)($auditCounts['Riesgo bajo'] ?? 0), 'color' => '#10b981'],
                    ['nivel' => '4.SIN RIESGO', 'cantidad' => 0, 'color' => '#9ca3af'],
                    ['nivel' => '5.SIN REGISTRO', 'cantidad' => (int)($auditCounts['Sin registro'] ?? 0), 'color' => '#6b7280'],
                ];

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

            return response()->json([
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
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Dashboard Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'error' => 'Error al obtener datos del dashboard',
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ], 500);
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
}

