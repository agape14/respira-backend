<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DashboardDerivacionesController extends Controller
{
    /**
     * Subgrupo 1: Total de Casos Derivados
     * Combina: pacientes de la tabla derivados + pacientes de alto riesgo (misma lógica que DerivacionController)
     */
    public function totalCasosDerivados(Request $request)
    {
        try {
            $filteredUserIds = $this->getFilteredUserIds($request);
            $dateRange = $this->getDateRange($request);

            // Obtener casos de alto riesgo usando la misma lógica que DerivacionController
            $essaludHighRisk = $this->getHighRiskCountByEntity('ESSALUD', $filteredUserIds);
            $minsaHighRisk = $this->getHighRiskCountByEntity('MINSA', $filteredUserIds);
            $highRiskTotal = $essaludHighRisk + $minsaHighRisk;

            Log::info('DashboardDerivaciones: totalCasosDerivados', [
                'essalud_high_risk' => $essaludHighRisk,
                'minsa_high_risk' => $minsaHighRisk,
                'high_risk_total' => $highRiskTotal,
            ]);

            // Casos de la tabla derivados
            $derivadosFromTableQuery = DB::table('derivados')
                ->select('paciente_id')
                ->distinct();

            if ($filteredUserIds !== null) {
                $derivadosFromTableQuery->whereIn('paciente_id', $filteredUserIds);
            }

            if ($dateRange !== null) {
                $this->applyDateRangeNvarchar($derivadosFromTableQuery, 'fecha', $dateRange);
            }

            $derivadosFromTable = $derivadosFromTableQuery->count();

            // Total: combinamos ambos (los de alto riesgo ya incluyen los de la tabla derivados)
            // Entonces el total es simplemente el máximo entre ambos
            $total = max($derivadosFromTable, $highRiskTotal);

            // Derivados desde Tamizaje: todos los de alto riesgo son por tamizaje
            $derivadosTamizaje = $highRiskTotal;

            // Derivados desde Intervención Breve (tipo M en tabla derivados)
            $derivadosIntervencionBreve = DB::table('derivados')
                ->where('tipo', 'M')
                ->when($filteredUserIds !== null, function($q) use ($filteredUserIds) {
                    $q->whereIn('paciente_id', $filteredUserIds);
                })
                ->when($dateRange !== null, function($q) use ($dateRange) {
                    $this->applyDateRangeNvarchar($q, 'fecha', $dateRange);
                })
                ->selectRaw('COUNT(DISTINCT paciente_id) as total')
                ->value('total') ?? 0;

            $porcentajeTamizaje = $total > 0 ? round(($derivadosTamizaje / $total) * 100) : 0;
            $porcentajeIntervencionBreve = $total > 0 ? round(($derivadosIntervencionBreve / $total) * 100) : 0;

            return response()->json([
                'total_casos_derivados' => $total,
                'derivados_tamizaje' => $derivadosTamizaje,
                'derivados_intervencion_breve' => $derivadosIntervencionBreve,
                'porcentaje_tamizaje' => $porcentajeTamizaje,
                'porcentaje_intervencion_breve' => $porcentajeIntervencionBreve,
            ]);
        } catch (\Exception $e) {
            Log::error('Error en totalCasosDerivados: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Subgrupo 2: Total Derivaciones (ESSALUD/MINSA) y Total Atendidos
     */
    public function totalDerivaciones(Request $request)
    {
        try {
            $filteredUserIds = $this->getFilteredUserIds($request);
            $dateRange = $this->getDateRange($request);

            // Obtener pacientes de alto riesgo por entidad (misma lógica que DerivacionController)
            $essaludHighRisk = $this->getHighRiskCountByEntity('ESSALUD', $filteredUserIds);
            $minsaHighRisk = $this->getHighRiskCountByEntity('MINSA', $filteredUserIds);

            // Combinar con derivados de la tabla (aunque esté vacía)
            $derivadosEssaludQuery = DB::table('derivados')
                ->join('usuarios', 'derivados.paciente_id', '=', 'usuarios.id')
                ->join('serumista_equivalentes_remunerados', DB::raw('CAST(usuarios.cmp AS VARCHAR)'), '=', DB::raw('CAST(serumista_equivalentes_remunerados.CMP AS VARCHAR)'))
                ->where('serumista_equivalentes_remunerados.INSTITUCION', 'LIKE', '%ESSALUD%')
                ->select('derivados.paciente_id')
                ->distinct();

            if ($filteredUserIds !== null) {
                $derivadosEssaludQuery->whereIn('derivados.paciente_id', $filteredUserIds);
            }

            if ($dateRange !== null) {
                $this->applyDateRangeNvarchar($derivadosEssaludQuery, 'derivados.fecha', $dateRange);
            }

            $derivadosEssalud = $derivadosEssaludQuery->count();

            $derivadosMinsaQuery = DB::table('derivados')
                ->join('usuarios', 'derivados.paciente_id', '=', 'usuarios.id')
                ->join('serumista_equivalentes_remunerados', DB::raw('CAST(usuarios.cmp AS VARCHAR)'), '=', DB::raw('CAST(serumista_equivalentes_remunerados.CMP AS VARCHAR)'))
                ->where('serumista_equivalentes_remunerados.INSTITUCION', 'LIKE', '%MINSA%')
                ->select('derivados.paciente_id')
                ->distinct();

            if ($filteredUserIds !== null) {
                $derivadosMinsaQuery->whereIn('derivados.paciente_id', $filteredUserIds);
            }

            if ($dateRange !== null) {
                $this->applyDateRangeNvarchar($derivadosMinsaQuery, 'derivados.fecha', $dateRange);
            }

            $derivadosMinsa = $derivadosMinsaQuery->count();

            // Combinar: alto riesgo + derivados de tabla
            $totalEssalud = max($essaludHighRisk, $derivadosEssalud);
            $totalMinsa = max($minsaHighRisk, $derivadosMinsa);
            $totalDerivaciones = $totalEssalud + $totalMinsa;

            // Derivaciones atendidas
            $atendidosEssaludQuery = DB::table('derivaciones_atencion')
                ->where('entidad', 'LIKE', '%ESSALUD%');

            if ($filteredUserIds !== null) {
                $atendidosEssaludQuery->whereIn('paciente_id', $filteredUserIds);
            }

            if ($dateRange !== null) {
                $this->applyDateRange($atendidosEssaludQuery, 'fecha_atencion', $dateRange);
            }

            $atendidosEssalud = $atendidosEssaludQuery->count();

            $atendidosMinsaQuery = DB::table('derivaciones_atencion')
                ->where('entidad', 'LIKE', '%MINSA%');

            if ($filteredUserIds !== null) {
                $atendidosMinsaQuery->whereIn('paciente_id', $filteredUserIds);
            }

            if ($dateRange !== null) {
                $this->applyDateRange($atendidosMinsaQuery, 'fecha_atencion', $dateRange);
            }

            $atendidosMinsa = $atendidosMinsaQuery->count();

            $totalAtendidos = $atendidosEssalud + $atendidosMinsa;
            $porcentajeAtendidos = $totalDerivaciones > 0 ? round(($totalAtendidos / $totalDerivaciones) * 100) : 0;

            return response()->json([
                'total_derivaciones' => $totalDerivaciones,
                'derivaciones_essalud' => $totalEssalud,
                'derivaciones_minsa' => $totalMinsa,
                'total_atendidos' => $totalAtendidos,
                'porcentaje_atendidos' => $porcentajeAtendidos,
                'atendidos_essalud' => $atendidosEssalud,
                'atendidos_minsa' => $atendidosMinsa,
            ]);
        } catch (\Exception $e) {
            Log::error('Error en totalDerivaciones: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Subgrupo 3: Derivaciones desde Tamizaje (ASQ, PHQ, GAD, MBI, AUDIT)
     */
    public function derivacionesTamizaje(Request $request)
    {
        try {
            $filteredUserIds = $this->getFilteredUserIds($request);
            $dateRange = $this->getDateRange($request);

            // ASQ: pacientes con resultado != 'Sin riesgo'
            $derivacionesAsqQuery = DB::table('usuarios')
                ->join('asq5_responses', 'usuarios.id', '=', 'asq5_responses.user_id')
                ->where('asq5_responses.resultado', '!=', 'Sin riesgo')
                ->whereRaw('asq5_responses.id = (SELECT MAX(id) FROM asq5_responses r2 WHERE r2.user_id = asq5_responses.user_id)')
                ->where('usuarios.estado', 1);

            if ($filteredUserIds !== null) {
                $derivacionesAsqQuery->whereIn('usuarios.id', $filteredUserIds);
            }

            $derivacionesAsq = $derivacionesAsqQuery->selectRaw('COUNT(DISTINCT usuarios.id) as total')
                ->value('total') ?? 0;

            // PHQ: pacientes con riesgo alto
            $derivacionesPhqQuery = DB::table('usuarios')
                ->join('phq9_responses', 'usuarios.id', '=', 'phq9_responses.user_id')
                ->where('phq9_responses.riesgo', 'Riesgo alto')
                ->whereRaw('phq9_responses.id_encuesta = (SELECT MAX(id_encuesta) FROM phq9_responses r2 WHERE r2.user_id = phq9_responses.user_id)')
                ->where('usuarios.estado', 1);

            if ($filteredUserIds !== null) {
                $derivacionesPhqQuery->whereIn('usuarios.id', $filteredUserIds);
            }

            $derivacionesPhq = $derivacionesPhqQuery->selectRaw('COUNT(DISTINCT usuarios.id) as total')
                ->value('total') ?? 0;

            // GAD: pacientes con riesgo alto
            $derivacionesGadQuery = DB::table('usuarios')
                ->join('gad_responses', 'usuarios.id', '=', 'gad_responses.user_id')
                ->where('gad_responses.riesgo', 'Riesgo alto')
                ->whereRaw('gad_responses.id_encuesta = (SELECT MAX(id_encuesta) FROM gad_responses r2 WHERE r2.user_id = gad_responses.user_id)')
                ->where('usuarios.estado', 1);

            if ($filteredUserIds !== null) {
                $derivacionesGadQuery->whereIn('usuarios.id', $filteredUserIds);
            }

            $derivacionesGad = $derivacionesGadQuery->selectRaw('COUNT(DISTINCT usuarios.id) as total')
                ->value('total') ?? 0;

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

            if ($filteredUserIds !== null) {
                $derivacionesMbiQuery->whereIn('usuarios.id', $filteredUserIds);
            }

            $derivacionesMbi = $derivacionesMbiQuery->selectRaw('COUNT(DISTINCT usuarios.id) as total')
                ->value('total') ?? 0;

            // AUDIT: pacientes con consumo problemático/dependencia/riesgo
            $derivacionesAuditQuery = DB::table('usuarios')
                ->join('audit_responses', 'usuarios.id', '=', 'audit_responses.user_id')
                ->whereIn('audit_responses.riesgo', ['Consumo problemático', 'Dependencia', 'Riesgo'])
                ->whereRaw('audit_responses.id_encuesta = (SELECT MAX(id_encuesta) FROM audit_responses r2 WHERE r2.user_id = audit_responses.user_id)')
                ->where('usuarios.estado', 1);

            if ($filteredUserIds !== null) {
                $derivacionesAuditQuery->whereIn('usuarios.id', $filteredUserIds);
            }

            $derivacionesAudit = $derivacionesAuditQuery->selectRaw('COUNT(DISTINCT usuarios.id) as total')
                ->value('total') ?? 0;

            $total = $derivacionesAsq + $derivacionesPhq + $derivacionesGad + $derivacionesMbi + $derivacionesAudit;

            return response()->json([
                'derivaciones_asq' => $derivacionesAsq,
                'derivaciones_phq' => $derivacionesPhq,
                'derivaciones_gad' => $derivacionesGad,
                'derivaciones_mbi' => $derivacionesMbi,
                'derivaciones_audit' => $derivacionesAudit,
                'total' => $total,
                'porcentaje_asq' => $total > 0 ? round(($derivacionesAsq / $total) * 100) : 0,
                'porcentaje_phq' => $total > 0 ? round(($derivacionesPhq / $total) * 100) : 0,
                'porcentaje_gad' => $total > 0 ? round(($derivacionesGad / $total) * 100) : 0,
                'porcentaje_mbi' => $total > 0 ? round(($derivacionesMbi / $total) * 100) : 0,
                'porcentaje_audit' => $total > 0 ? round(($derivacionesAudit / $total) * 100) : 0,
            ]);
        } catch (\Exception $e) {
            Log::error('Error en derivacionesTamizaje: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener conteo de pacientes de alto riesgo por entidad
     * Usa exactamente la misma lógica que DerivacionController::getBaseQuery + applyHighRiskFilter
     */
    private function getHighRiskCountByEntity($entidad, $filteredUserIds = null)
    {
        $tableName = $entidad === 'MINSA' ? 'serumista_equivalentes_remunerados' : 'serumista_equivalentes_remunerados';

        // Construir query base (misma lógica que DerivacionController::getBaseQuery)
        // Usar CAST para asegurar que el join funcione correctamente con SQL Server
        $query = DB::table($tableName)
            ->join('usuarios', DB::raw("CAST({$tableName}.CMP AS VARCHAR)"), '=', DB::raw('CAST(usuarios.cmp AS VARCHAR)'))
            ->where('usuarios.estado', 1);

        // Aplicar filtro de usuarios si existe
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
            // AUDIT - Consumo problemático/Dependencia/Riesgo
            ->orWhereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('audit_responses')
                    ->whereColumn('audit_responses.user_id', 'usuarios.id')
                    ->whereIn('riesgo', ['Consumo problemático', 'Dependencia', 'Riesgo'])
                    ->whereRaw('id_encuesta = (SELECT MAX(id_encuesta) FROM audit_responses r2 WHERE r2.user_id = audit_responses.user_id)');
            })
            // ASQ - Cualquier riesgo
            ->orWhereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('asq5_responses')
                    ->whereColumn('asq5_responses.user_id', 'usuarios.id')
                    ->where('resultado', '!=', 'Sin riesgo')
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
     * Obtener query builder de usuarios filtrados (misma lógica que DashboardController)
     */
    private function getFilteredUserIds($request)
    {
        $departamento = $request->input('departamento', '');
        $institucion = $request->input('institucion', '');
        $modalidad = $request->input('modalidad', '');

        // Si no hay filtros, retornar null para obtener todos
        if (!$departamento && !$institucion && !$modalidad) {
            return null;
        }

        // Construir query de usuarios filtrados (misma lógica que DashboardController)
        $userQuery = DB::table('usuarios')
            ->join('serumista_equivalentes_remunerados', DB::raw('CAST(usuarios.cmp AS VARCHAR)'), '=', DB::raw('CAST(serumista_equivalentes_remunerados.CMP AS VARCHAR)'))
            ->where('usuarios.estado', 1)
            ->select('usuarios.id');

        if ($departamento) {
            $userQuery->where('serumista_equivalentes_remunerados.DEPARTAMENTO', $departamento);
        }
        if ($institucion) {
            $userQuery->where('serumista_equivalentes_remunerados.INSTITUCION', 'LIKE', '%' . $institucion . '%');
        }
        if ($modalidad === 'REMUNERADO') {
            $userQuery->where('serumista_equivalentes_remunerados.MODALIDAD', 'REMUNERADOS');
        }
        if ($modalidad === 'EQUIVALENTE') {
            $userQuery->where('serumista_equivalentes_remunerados.MODALIDAD', 'EQUIVALENTES');
        }

        // Retornar la query (subquery) para usar en whereIn
        return $userQuery;
    }

    /**
     * Obtener rango de fechas basado en proceso (misma lógica que DashboardController)
     */
    private function getDateRange($request)
    {
        $idProceso = $request->input('id_proceso', '');
        $corteLegacy = $request->input('corte', '');

        $dateRange = null;
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

        return $dateRange;
    }

    /**
     * Aplicar filtro de rango de fechas a columnas datetime
     */
    private function applyDateRange($query, $column, $dateRange)
    {
        if ($dateRange === null) return;
        $query->whereBetween($column, [$dateRange['start'], $dateRange['end']]);
    }

    /**
     * Aplicar filtro de rango de fechas a columnas NVARCHAR (evita errores de conversión en SQL Server)
     */
    private function applyDateRangeNvarchar($query, $column, $dateRange)
    {
        if ($dateRange === null) return;
        $expr = "COALESCE(
            TRY_CONVERT(datetime2, {$column}, 120),
            TRY_CONVERT(datetime2, {$column}, 126),
            TRY_CONVERT(datetime2, {$column}, 23)
        )";
        $query->whereRaw(
            "{$expr} BETWEEN ? AND ?",
            [$dateRange['start']->format('Y-m-d H:i:s'), $dateRange['end']->format('Y-m-d H:i:s')]
        );
    }
}
