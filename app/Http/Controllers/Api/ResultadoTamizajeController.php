<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Border;

class ResultadoTamizajeController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/tamizajes",
     *     summary="Obtener listado de resultados de tamizaje paginados",
     *     description="Retorna la lista de serumistas con sus evaluaciones psicológicas. Lógica basada en el sistema anterior de CodeIgniter.",
     *     tags={"Resultado Tamizaje"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Número de página",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Cantidad de registros por página",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Valor a buscar (a partir de 3 caracteres)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="search_tipo",
     *         in="query",
     *         description="Tipo de búsqueda: nombres, cmp o celular (celular = coincidencia exacta por dígitos)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"nombres", "cmp", "celular"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de resultados de tamizaje obtenida exitosamente"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Error al obtener resultados de tamizaje"
     *     )
     * )
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $page = $request->get('page', 1);
            $search = $request->get('search');
            $searchTipo = $request->get('search_tipo', 'nombres'); // cmp | nombres | celular
            $tipo = $request->get('tipo');
            $fechaInicio = $request->get('fecha_inicio');
            $fechaFin = $request->get('fecha_fin');
            $idProceso = $request->get('id_proceso');
            $idProceso = (isset($idProceso) && $idProceso !== '' && ctype_digit((string) $idProceso)) ? (int) $idProceso : null;

            $proceso = null;
            $corteEtiqueta = '';
            if ($idProceso !== null) {
                try {
                    $proceso = DB::connection('sqlsrv')->table('procesos')->where('id_proceso', $idProceso)->first();
                    if ($proceso && !empty($proceso->etiqueta)) {
                        $corteEtiqueta = $proceso->etiqueta;
                    }
                } catch (\Throwable $e) {
                    $proceso = null;
                    $corteEtiqueta = '';
                }
            }

            // CTE para preparar los datos y calcular la fecha de última evaluación
            // Cambio: partir desde usuarios (que tienen evaluaciones) y hacer LEFT JOIN a serumistas
            $cte = "
                WITH TamizajeData AS (
                    SELECT
                        u.id AS id,
                        COALESCE(s.NumeroDocumento, u.nombre_usuario) AS dni,
                        COALESCE(s.[APELLIDOS Y NOMBRES], u.nombre_completo) AS nombre_completo,
                        COALESCE(s.CMP, u.cmp) AS cmp,
                        COALESCE(u.telefono, '') AS telefono,

                        asq.resultado AS asq, asq.fecha_registro AS asq_fecha, asq.id AS asq_id,
                        phq.riesgo AS phq, phq.puntaje AS phq_puntaje, phq.fecha AS phq_fecha, phq.id_encuesta AS phq_id,
                        gad.riesgo AS gad, gad.puntaje AS gad_puntaje, gad.fecha AS gad_fecha, gad.id_encuesta AS gad_id,
                        (ISNULL(CAST(mbi.riesgoCE AS NVARCHAR(50)), N'') + N'-' + ISNULL(CAST(mbi.riesgoDP AS NVARCHAR(50)), N'') + N'-' + ISNULL(CAST(mbi.riesgoRP AS NVARCHAR(50)), N'')) AS mbi, mbi.fecha AS mbi_fecha, mbi.id_encuesta AS mbi_id,
                        audit.riesgo AS audit, audit.puntaje AS audit_puntaje, audit.fecha AS audit_fecha, audit.id_encuesta AS audit_id,

                        (SELECT MAX(f) FROM (SELECT asq.fecha_registro AS f UNION ALL SELECT phq.fecha UNION ALL SELECT gad.fecha UNION ALL SELECT mbi.fecha UNION ALL SELECT audit.fecha) AS fechas) as fecha_ultima_evaluacion

                    FROM usuarios u
                    LEFT JOIN serumista_remunerados s ON u.cmp = s.CMP

                    OUTER APPLY (SELECT TOP 1 id, resultado, fecha_registro FROM asq5_responses WHERE user_id = u.id ORDER BY id DESC) asq
                    OUTER APPLY (SELECT TOP 1 id_encuesta, riesgo, puntaje, fecha FROM phq9_responses WHERE user_id = u.id ORDER BY id_encuesta DESC) phq
                    OUTER APPLY (SELECT TOP 1 id_encuesta, riesgo, puntaje, fecha FROM gad_responses WHERE user_id = u.id ORDER BY id_encuesta DESC) gad
                    OUTER APPLY (SELECT TOP 1 id_encuesta, riesgoCE, riesgoDP, riesgoRP, fecha FROM mbi_responses WHERE user_id = u.id ORDER BY id_encuesta DESC) mbi
                    OUTER APPLY (SELECT TOP 1 id_encuesta, riesgo, puntaje, fecha FROM audit_responses WHERE user_id = u.id ORDER BY id_encuesta DESC) audit

                    WHERE u.estado = 1
                    AND (asq.id IS NOT NULL OR phq.id_encuesta IS NOT NULL OR gad.id_encuesta IS NOT NULL OR mbi.id_encuesta IS NOT NULL OR audit.id_encuesta IS NOT NULL)
                )
            ";

            // Construir cláusulas WHERE
            $where = " WHERE 1=1";
            $params = [];

            // Búsqueda específica por tipo; mínimos: nombres 4, CMP 5, celular 8
            $searchTrim = $search ? trim($search) : '';
            $minLen = $searchTipo === 'celular' ? 8 : ($searchTipo === 'cmp' ? 5 : 4);
            $searchOk = $searchTrim !== '' && strlen($searchTrim) >= $minLen;
            if ($searchTipo === 'celular') {
                $searchDigits = preg_replace('/\D/', '', $searchTrim);
                $searchOk = $searchOk && strlen($searchDigits) >= 8;
            }
            if ($searchOk) {
                if ($searchTipo === 'celular') {
                    $searchDigits = preg_replace('/\D/', '', $searchTrim);
                    if ($searchDigits !== '') {
                        $where .= " AND (REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefono, N' ', N''), N'-', N''), N'+', N''), N'.', N''), N',', N''), N'(', N''), N')', N''), N'/', N'') = ?)";
                        $params[] = $searchDigits;
                    }
                } elseif ($searchTipo === 'cmp') {
                    $where .= " AND cmp LIKE ?";
                    $params[] = '%' . $searchTrim . '%';
                } else {
                    $where .= " AND nombre_completo LIKE ?";
                    $params[] = '%' . $searchTrim . '%';
                }
            }

            if ($tipo && $tipo !== 'todos') {
                switch ($tipo) {
                    case 'asq': $where .= " AND asq_id IS NOT NULL"; break;
                    case 'phq': $where .= " AND phq_id IS NOT NULL"; break;
                    case 'gad': $where .= " AND gad_id IS NOT NULL"; break;
                    case 'mbi': $where .= " AND mbi_id IS NOT NULL"; break;
                    case 'audit': $where .= " AND audit_id IS NOT NULL"; break;
                }
            }

            if ($fechaInicio) {
                $where .= " AND CAST(fecha_ultima_evaluacion AS DATE) >= CAST(? AS DATE)";
                $params[] = $fechaInicio;
            }

            if ($fechaFin) {
                $where .= " AND CAST(fecha_ultima_evaluacion AS DATE) <= CAST(? AS DATE)";
                $params[] = $fechaFin;
            }

            if ($proceso) {
                $where .= " AND CAST(fecha_ultima_evaluacion AS DATE) >= CAST(? AS DATE) AND CAST(fecha_ultima_evaluacion AS DATE) <= CAST(? AS DATE)";
                $params[] = $proceso->fecha_inicio;
                $params[] = $proceso->fecha_fin;
            }

            // Contar total de registros
            $countSql = $cte . " SELECT COUNT(*) as total FROM TamizajeData" . $where;
            $totalResult = DB::connection('sqlsrv')->select($countSql, $params);
            $total = $totalResult[0]->total;

            // Obtener datos paginados
            $offset = ($page - 1) * $perPage;
            $dataSql = $cte . " SELECT * FROM TamizajeData" . $where . "
                ORDER BY
                    CASE WHEN fecha_ultima_evaluacion IS NOT NULL THEN 0 ELSE 1 END,
                    fecha_ultima_evaluacion DESC,
                    nombre_completo ASC
                OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";

            $resultados = DB::connection('sqlsrv')->select($dataSql, $params);

            // Cargar procesos activos para asignar etiqueta por fecha (probar sqlsrv y luego conexión por defecto)
            $procesosList = [];
            try {
                $procesosList = DB::connection('sqlsrv')->table('procesos')->where('activo', 1)->orderBy('fecha_inicio')->get();
                if ($procesosList->isEmpty()) {
                    $procesosList = DB::table('procesos')->where('activo', 1)->orderBy('fecha_inicio')->get();
                }
            } catch (\Throwable $e) {
                try {
                    $procesosList = DB::table('procesos')->where('activo', 1)->orderBy('fecha_inicio')->get();
                } catch (\Throwable $e2) {
                    // ignorar
                }
            }
            $procesosList = collect($procesosList ?? []);

            // Procesar los resultados
            $data = array_map(function ($item) use ($corteEtiqueta, $proceso, $procesosList) {
                $completadas = 0;
                $totalEvaluaciones = 5;

                if (!empty($item->asq_id)) $completadas++;
                if (!empty($item->phq_id)) $completadas++;
                if (!empty($item->gad_id)) $completadas++;
                if (!empty($item->mbi_id)) $completadas++;
                if (!empty($item->audit_id)) $completadas++;

                // Etiqueta: si hay filtro por corte usarla; si no, calcular por fecha_ultima_evaluacion
                $etiqueta = $corteEtiqueta;
                if (empty($etiqueta) && $item->fecha_ultima_evaluacion && $procesosList->isNotEmpty()) {
                    $fecha = $item->fecha_ultima_evaluacion;
                    $f = $fecha instanceof \DateTimeInterface ? $fecha->format('Y-m-d') : (is_string($fecha) ? substr($fecha, 0, 10) : (string) $fecha);
                    foreach ($procesosList as $p) {
                        if (!empty($p->fecha_inicio) && !empty($p->fecha_fin) && !empty($p->etiqueta)) {
                            $inicio = $p->fecha_inicio instanceof \DateTimeInterface ? $p->fecha_inicio->format('Y-m-d') : substr((string) $p->fecha_inicio, 0, 10);
                            $fin = $p->fecha_fin instanceof \DateTimeInterface ? $p->fecha_fin->format('Y-m-d') : substr((string) $p->fecha_fin, 0, 10);
                            if ($f >= $inicio && $f <= $fin) {
                                $etiqueta = $p->etiqueta;
                                break;
                            }
                        }
                    }
                }
                if ($etiqueta === '') {
                    $etiqueta = '-';
                }

                return [
                    'id' => $item->id,
                    'dni' => $item->dni ?? '',
                    'cmp' => $item->cmp ?? '',
                    'nombre_completo' => $item->nombre_completo ?? '',
                    'completadas' => $completadas,
                    'total_evaluaciones' => $totalEvaluaciones,
                    'corte_etiqueta' => $etiqueta,
                    'asq' => $item->asq ?? null,
                    'asq_fecha' => $item->asq_fecha ?? null,
                    'phq' => $item->phq ?? null,
                    'phq_puntaje' => $item->phq_puntaje ?? null,
                    'phq_fecha' => $item->phq_fecha ?? null,
                    'gad' => $item->gad ?? null,
                    'gad_puntaje' => $item->gad_puntaje ?? null,
                    'gad_fecha' => $item->gad_fecha ?? null,
                    'mbi' => $item->mbi ?? null,
                    'mbi_fecha' => $item->mbi_fecha ?? null,
                    'audit' => $item->audit ?? null,
                    'audit_puntaje' => $item->audit_puntaje ?? null,
                    'audit_fecha' => $item->audit_fecha ?? null,
                    'fecha_ultima_evaluacion' => $item->fecha_ultima_evaluacion ?? null
                ];
            }, $resultados);

            $lastPage = ceil($total / $perPage);

            return response()->json([
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'from' => $offset + 1,
                    'to' => min($offset + $perPage, $total)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al obtener resultados de tamizaje',
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/tamizajes/{dni}",
     *     summary="Obtener detalle de un tamizaje por DNI",
     *     description="Retorna el detalle completo de las evaluaciones de un serumista",
     *     tags={"Resultado Tamizaje"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="dni",
     *         in="path",
     *         description="DNI del serumista",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Detalle del tamizaje obtenido exitosamente"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Serumista no encontrado"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Error al obtener detalle"
     *     )
     * )
     */
    public function show($dni)
    {
        try {
            // Buscar serumista por DNI o CMP (JOIN con usuarios por CMP para obtener el id)
            $sql = "SELECT TOP 1
                        COALESCE(u.id, 0) as id,
                        s.CMP as cmp,
                        s.[APELLIDOS Y NOMBRES] as nombre_completo,
                        s.NumeroDocumento as dni,
                        s.Email as email
                    FROM serumista_remunerados s
                    LEFT JOIN usuarios u ON s.CMP = u.cmp AND u.estado = 1
                    WHERE s.NumeroDocumento = ? OR s.CMP = ?";

            $serumista = DB::connection('sqlsrv')->select($sql, [$dni, $dni]);

            if (empty($serumista)) {
                return response()->json(['error' => 'Serumista no encontrado'], 404);
            }

            $serumista = $serumista[0];
            $userId = $serumista->id;

            // Si no hay usuario vinculado (id = 0), no hay evaluaciones
            if ($userId == 0) {
                return response()->json([
                    'success' => true,
                    'serumista' => $serumista,
                    'evaluaciones' => [
                        'asq' => [],
                        'phq9' => [],
                        'gad' => [],
                        'mbi' => [],
                        'audit' => []
                    ],
                ]);
            }

            // Obtener TODAS las evaluaciones históricas de las tablas (ordenadas por fecha descendente)
            $evaluaciones = [];

            // ASQ5 - Todas las evaluaciones con puntuación e interpretación calculadas
            $asqRaw = DB::connection('sqlsrv')->select(
                "SELECT * FROM asq5_responses WHERE user_id = ? ORDER BY fecha_registro DESC",
                [$userId]
            );

            // Procesar ASQ para agregar puntuación e interpretación
            $asq = array_map(function($item) {
                // Calcular puntuación: cada respuesta "Si" cuenta como 1 punto
                $puntaje = 0;
                $preguntas = ['pregunta1', 'pregunta2', 'pregunta3', 'pregunta4', 'pregunta5'];
                foreach ($preguntas as $pregunta) {
                    if (isset($item->$pregunta) && strtolower(trim($item->$pregunta)) === 'si') {
                        $puntaje++;
                    }
                }

                // Determinar interpretación basada en el resultado
                $interpretacion = '';
                $resultado = $item->resultado ?? '';
                $resultadoLower = strtolower($resultado);

                if (strpos($resultadoLower, 'agudo') !== false || strpos($resultadoLower, 'inminente') !== false) {
                    $interpretacion = 'Se requiere atención inmediata y evaluación de emergencia. Contacto urgente con servicios de salud mental.';
                } elseif (strpos($resultadoLower, 'no agudo') !== false) {
                    $interpretacion = 'Se sugiere monitoreo continuo y seguimiento en 3 meses. Evaluación profesional recomendada.';
                } elseif (strpos($resultadoLower, 'sin riesgo') !== false) {
                    $interpretacion = 'Nivel bajo de síntomas. Sin necesidad de intervención inmediata. Continuar con seguimiento regular.';
                } else {
                    $interpretacion = 'Evaluación completada. Se recomienda seguimiento según protocolo.';
                }

                // Agregar campos calculados al objeto
                $item->puntaje = $puntaje;
                $item->interpretacion = $interpretacion;

                return $item;
            }, $asqRaw);

            $evaluaciones['asq'] = $asq;

            // PHQ9 - Todas las evaluaciones
            $phq = DB::connection('sqlsrv')->select(
                "SELECT * FROM phq9_responses WHERE user_id = ? ORDER BY fecha DESC",
                [$userId]
            );
            $evaluaciones['phq9'] = $phq;

            // GAD - Todas las evaluaciones
            $gad = DB::connection('sqlsrv')->select(
                "SELECT * FROM gad_responses WHERE user_id = ? ORDER BY fecha DESC",
                [$userId]
            );
            $evaluaciones['gad'] = $gad;

            // MBI - Todas las evaluaciones
            $mbi = DB::connection('sqlsrv')->select(
                "SELECT * FROM mbi_responses WHERE user_id = ? ORDER BY fecha DESC",
                [$userId]
            );
            $evaluaciones['mbi'] = $mbi;

            // AUDIT - Todas las evaluaciones
            $audit = DB::connection('sqlsrv')->select(
                "SELECT * FROM audit_responses WHERE user_id = ? ORDER BY fecha DESC",
                [$userId]
            );
            $evaluaciones['audit'] = $audit;

            return response()->json([
                'success' => true,
                'serumista' => $serumista,
                'evaluaciones' => $evaluaciones,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al obtener detalle',
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function exportarIndividual(Request $request, $dni)
    {
        // Individual: no filtrar por proceso; permitir buscar por CMP si viene para localizar al serumista
        $cmp = $request->get('cmp');
        return $this->generarExcel($dni, null, null, $cmp);
    }

    public function exportarTodo(Request $request)
    {
        $search = $request->get('search');
        $searchTipo = $request->get('search_tipo', 'nombres');
        $idProceso = $request->get('id_proceso');
        return $this->generarExcel(null, $search, $idProceso, null, $searchTipo);
    }

    private function generarExcel($dni = null, $search = null, $idProceso = null, $cmp = null, $searchTipo = 'nombres')
    {
        try {
            $proceso = null;
            $idProceso = (isset($idProceso) && $idProceso !== '' && ctype_digit((string) $idProceso)) ? (int) $idProceso : null;
            // Solo filtrar por proceso en exportación grupal; en individual ($dni) no filtrar para que siempre haya registro
            if ($idProceso !== null && $dni === null) {
                try {
                    $proceso = DB::connection('sqlsrv')->table('procesos')->where('id_proceso', $idProceso)->first();
                } catch (\Throwable $e) {
                    $proceso = null;
                }
            }

            // Lista de procesos para calcular CORTE por fila (probar sqlsrv y luego conexión por defecto)
            $procesosList = collect([]);
            try {
                $procesosList = DB::connection('sqlsrv')->table('procesos')->where('activo', 1)->orderBy('fecha_inicio')->get();
                if ($procesosList->isEmpty()) {
                    $procesosList = DB::table('procesos')->where('activo', 1)->orderBy('fecha_inicio')->get();
                }
            } catch (\Throwable $e) {
                try {
                    $procesosList = DB::table('procesos')->where('activo', 1)->orderBy('fecha_inicio')->get();
                } catch (\Throwable $e2) {
                    // ignorar
                }
            }
            $procesosList = collect($procesosList ?? []);

            // Misma base que el grid: usuarios con evaluaciones + LEFT JOIN serumista (para que grid y Excel listen los mismos registros)
            $sql = "
                SELECT
                    ROW_NUMBER() OVER (ORDER BY COALESCE(s.[APELLIDOS Y NOMBRES], u.nombre_completo)) AS contador,
                    COALESCE(s.[APELLIDOS Y NOMBRES], u.nombre_completo) AS nombre_completo,
                    '' AS edad,
                    '' AS grupo_etareo,
                    CASE
                        WHEN TRY_CONVERT(INT, mat.FlagMasculino) = 1 OR UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(10))))) = 'M' THEN N'Masculino'
                        WHEN TRY_CONVERT(INT, mat.FlagMasculino) = 0 OR UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(10))))) = 'F' THEN N'Femenino'
                        WHEN UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(20))))) LIKE N'%MASCULINO%' THEN N'Masculino'
                        WHEN UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(20))))) LIKE N'%FEMENINO%' THEN N'Femenino'
                        ELSE NULL
                    END AS sexo,
                    COALESCE(s.NumeroDocumento, u.nombre_usuario) AS dni,
                    COALESCE(s.CMP, u.cmp) AS cmp,
                    u.telefono AS celular,
                    s.Email AS correo,
                    s.MODALIDAD AS modalidad,
                    s.DIRESA_GERESA_DIRIS AS diresa_geresa_diris,
                    s.INSTITUCION AS institucion,
                    s.DEPARTAMENTO AS departamento,
                    s.PROVINCIA AS provincia,
                    s.DISTRITO AS distrito,
                    s.[GRADO DE DIFICULTAD] AS grado_de_dificultad,
                    s.CODIGO_RENIPRESS_MODULAR AS codigo_renipress_modular,
                    s.[NOMBRE DE ESTABLECIMIENTO] AS nombre_de_establecimiento,
                    s.CATEGORIA AS categoria,
                    s.PRESUPUESTO AS presupuesto,
                    s.[ZAF (*)] AS zaf,
                    s.[ZE (**)] AS ze,

                    asq.resultado AS asq_resultado,
                    asq.fecha_registro AS asq_fecha,
                    asq.pregunta1, asq.pregunta2, asq.pregunta3, asq.pregunta4, asq.pregunta5,

                    phq.riesgo AS phq_riesgo,
                    phq.puntaje AS phq_puntaje,
                    phq.fecha AS phq_fecha,

                    gad.riesgo AS gad_riesgo,
                    gad.puntaje AS gad_puntaje,
                    gad.fecha AS gad_fecha,

                    audit.riesgo AS audit_riesgo,
                    audit.puntaje AS audit_puntaje,
                    audit.fecha AS audit_fecha,

                    mbi.riesgoCE AS mbi_riesgo,
                    mbi.riesgoDP,
                    mbi.riesgoRP,
                    mbi.puntajeCE AS mbi_puntaje,
                    mbi.fecha AS mbi_fecha

                FROM usuarios u
                LEFT JOIN serumista_remunerados s ON u.cmp = s.CMP
                LEFT JOIN [CMP02].[db_cmp].[dbo].[Mat_Colegiado] mat ON CAST(u.cmp AS VARCHAR(20)) = CAST(mat.Colegiado_Id AS VARCHAR(20))

                OUTER APPLY (
                    SELECT TOP 1 resultado, fecha_registro, pregunta1, pregunta2, pregunta3, pregunta4, pregunta5
                    FROM asq5_responses
                    WHERE user_id = u.id
                    " . ($proceso ? " AND (CAST(fecha_registro AS DATE) >= ? AND CAST(fecha_registro AS DATE) <= ?)" : "") . "
                    ORDER BY id DESC
                ) asq

                OUTER APPLY (
                    SELECT TOP 1 riesgo, puntaje, fecha
                    FROM phq9_responses
                    WHERE user_id = u.id
                    " . ($proceso ? " AND (CAST(fecha AS DATE) >= ? AND CAST(fecha AS DATE) <= ?)" : "") . "
                    ORDER BY id_encuesta DESC
                ) phq

                OUTER APPLY (
                    SELECT TOP 1 riesgo, puntaje, fecha
                    FROM gad_responses
                    WHERE user_id = u.id
                    " . ($proceso ? " AND (CAST(fecha AS DATE) >= ? AND CAST(fecha AS DATE) <= ?)" : "") . "
                    ORDER BY id_encuesta DESC
                ) gad

                OUTER APPLY (
                    SELECT TOP 1 riesgoCE, riesgoDP, riesgoRP, puntajeCE, fecha
                    FROM mbi_responses
                    WHERE user_id = u.id
                    " . ($proceso ? " AND (CAST(fecha AS DATE) >= ? AND CAST(fecha AS DATE) <= ?)" : "") . "
                    ORDER BY id_encuesta DESC
                ) mbi

                OUTER APPLY (
                    SELECT TOP 1 riesgo, puntaje, fecha
                    FROM audit_responses
                    WHERE user_id = u.id
                    " . ($proceso ? " AND (CAST(fecha AS DATE) >= ? AND CAST(fecha AS DATE) <= ?)" : "") . "
                    ORDER BY id_encuesta DESC
                ) audit

                WHERE u.estado = 1
                AND (asq.resultado IS NOT NULL OR phq.riesgo IS NOT NULL OR gad.riesgo IS NOT NULL OR mbi.riesgoCE IS NOT NULL OR audit.riesgo IS NOT NULL)
            ";

            $params = [];

            if ($proceso) {
                for ($i = 0; $i < 5; $i++) {
                    $params[] = $proceso->fecha_inicio;
                    $params[] = $proceso->fecha_fin;
                }
            }

            if ($dni) {
                $sql .= " AND (u.nombre_usuario = ? OR u.cmp = ? OR s.NumeroDocumento = ? OR s.CMP = ?)";
                $params[] = $dni;
                $params[] = $dni;
                $params[] = $dni;
                $params[] = ($cmp !== null && $cmp !== '') ? $cmp : $dni;
            }

            $searchTrim = $search ? trim($search) : '';
            $minLen = $searchTipo === 'celular' ? 8 : ($searchTipo === 'cmp' ? 5 : 4);
            $searchOk = $searchTrim !== '' && strlen($searchTrim) >= $minLen;
            if ($searchTipo === 'celular') {
                $searchDigits = preg_replace('/\D/', '', $searchTrim);
                $searchOk = $searchOk && strlen($searchDigits) >= 8;
            }
            if ($searchOk) {
                if ($searchTipo === 'celular') {
                    $searchDigits = preg_replace('/\D/', '', $searchTrim);
                    if ($searchDigits !== '') {
                        $sql .= " AND (REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(u.telefono, N' ', N''), N'-', N''), N'+', N''), N'.', N''), N',', N''), N'(', N''), N')', N''), N'/', N'') = ?)";
                        $params[] = $searchDigits;
                    }
                } elseif ($searchTipo === 'cmp') {
                    $sql .= " AND (COALESCE(s.CMP, u.cmp) LIKE ?)";
                    $params[] = '%' . $searchTrim . '%';
                } else {
                    $sql .= " AND (COALESCE(s.[APELLIDOS Y NOMBRES], u.nombre_completo) LIKE ?)";
                    $params[] = '%' . $searchTrim . '%';
                }
            }

            if ($proceso) {
                $sql .= " AND (asq.fecha_registro IS NOT NULL OR phq.fecha IS NOT NULL OR gad.fecha IS NOT NULL OR mbi.fecha IS NOT NULL OR audit.fecha IS NOT NULL)";
            }

            $sql .= " ORDER BY COALESCE(s.[APELLIDOS Y NOMBRES], u.nombre_completo)";

            $resultados = DB::connection('sqlsrv')->select($sql, $params);

            // Crear Excel
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Cabeceras
            $cabeceras = [
                'contador' => 'N°',
                'corte' => 'CORTE',
                'nombre_completo' => 'NOMBRE COMPLETO',
                'edad' => 'EDAD',
                'grupo_etareo' => 'GRUPO ETAREO',
                'sexo' => 'SEXO',
                'dni' => 'DNI',
                'cmp' => 'CMP',
                'celular' => 'CELULAR',
                'correo' => 'CORREO',
                'modalidad' => 'MODALIDAD',
                'diresa_geresa_diris' => 'DIRESA_GERESA_DIRIS',
                'institucion' => 'INSTITUCIÓN',
                'departamento' => 'DEPARTAMENTO',
                'provincia' => 'PROVINCIA',
                'distrito' => 'DISTRITO',
                'grado_de_dificultad' => 'GRADO DE DIFICULTAD',
                'codigo_renipress_modular' => 'CODIGO_RENIPRESS_MODULAR',
                'nombre_de_establecimiento' => 'ESTABLECIMIENTO',
                'categoria' => 'CATEGORÍA',
                'presupuesto' => 'PRESUPUESTO',
                'zaf' => 'ZAF (*)',
                'ze' => 'ZE (**)',
                'riesgo_suicida_agudo' => 'INMINENTE = RIESGO SUICIDA AGUDO',
                'fecha_suicidio_agudo' => 'FECHA DE REALIZACIÓN DEL TEST',
                'riesgo_suicida_no_agudo' => 'RIESGO SUICIDA NO AGUDO',
                'fecha_suicidio_no_agudo' => 'FECHA DE REALIZACIÓN DEL TEST',
                'informe_asq' => 'INFORME DE EVALUACIÓN',
                'depresion' => 'DEPRESIÓN',
                'fecha_depresion' => 'FECHA DE REALIZACIÓN DEL TEST',
                'informe_phq' => 'INFORME DE EVALUACIÓN',
                'puntaje_phq' => 'PUNTAJE DEPRESIÓN',
                'ansiedad' => 'ANSIEDAD',
                'fecha_ansiedad' => 'FECHA DE REALIZACIÓN DEL TEST',
                'informe_gad' => 'INFORME DE EVALUACIÓN',
                'puntaje_gad' => 'PUNTAJE ANSIEDAD',
                'alcohol' => 'ALCOHOLISMO',
                'fecha_alcohol' => 'FECHA DE REALIZACIÓN DEL TEST',
                'informe_aud' => 'INFORME DE EVALUACIÓN',
                'puntaje_aud' => 'PUNTAJE ALCOHOLISMO',
                'burnout' => 'BURNOUT',
                'fecha_burnout' => 'FECHA DE REALIZACIÓN DEL TEST',
                'informe_mbi' => 'INFORME DE EVALUACIÓN',
                'puntaje_ce' => 'PUNTAJE BURNOUT'
            ];

            $col = 'A';
            foreach ($cabeceras as $nombre) {
                $sheet->setCellValue($col.'1', $nombre);
                $col++;
            }

            // Estilo Cabecera
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4F81BD']
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);

            $row = 2;
            foreach ($resultados as $r) {
                // Procesar lógica de columnas calculadas (Riesgo Agudo/No Agudo)
                $riesgoAgudo = '';
                $fechaAgudo = '';
                $riesgoNoAgudo = '';
                $fechaNoAgudo = '';

                // Lógica exacta de Reportes.php
                if ($r->asq_resultado) {
                    $res = trim($r->asq_resultado); // Case sensitive check first, or normalize? Reportes.php uses exact strings usually.

                    // Normalizar para comparación segura
                    $resLower = strtolower($res);

                    if ($resLower === 'riesgo suicida agudo/inminente') {
                        $riesgoAgudo = 'Sí';
                        $fechaAgudo = $r->asq_fecha;
                        $riesgoNoAgudo = 'No';
                        $fechaNoAgudo = null;
                    } elseif ($resLower === 'riesgo suicida no agudo') {
                        $riesgoAgudo = 'No';
                        $fechaAgudo = null;
                        $riesgoNoAgudo = 'Sí';
                        $fechaNoAgudo = $r->asq_fecha;
                    } elseif ($resLower === 'sin riesgo') {
                        $riesgoAgudo = 'Sin riesgo';
                        $fechaAgudo = $r->asq_fecha;
                        $riesgoNoAgudo = 'Sin riesgo';
                        $fechaNoAgudo = $r->asq_fecha;
                    }
                }

                // Generar informes
                $informeAsq = $this->generarInforme('asq', $r);
                $informePhq = $this->generarInforme('phq', $r);
                $informeGad = $this->generarInforme('gad', $r);
                $informeAudit = $this->generarInforme('audit', $r);
                $informeMbi = $this->generarInforme('mbi', $r);

                // CORTE por fila: si hay filtro por proceso usarlo; si no, calcular por la fecha más reciente de evaluaciones
                $corteFila = ($proceso && !empty($proceso->etiqueta)) ? $proceso->etiqueta : 'Todos los cortes';
                if (empty($proceso) && $procesosList->isNotEmpty()) {
                    $fechas = array_filter([$r->asq_fecha ?? null, $r->phq_fecha ?? null, $r->gad_fecha ?? null, $r->mbi_fecha ?? null, $r->audit_fecha ?? null]);
                    $fechaMax = $fechas ? max($fechas) : null;
                    if ($fechaMax) {
                        $f = $fechaMax instanceof \DateTimeInterface ? $fechaMax->format('Y-m-d') : substr((string) $fechaMax, 0, 10);
                        foreach ($procesosList as $p) {
                            if (!empty($p->fecha_inicio) && !empty($p->fecha_fin) && !empty($p->etiqueta)) {
                                $inicio = $p->fecha_inicio instanceof \DateTimeInterface ? $p->fecha_inicio->format('Y-m-d') : substr((string) $p->fecha_inicio, 0, 10);
                                $fin = $p->fecha_fin instanceof \DateTimeInterface ? $p->fecha_fin->format('Y-m-d') : substr((string) $p->fecha_fin, 0, 10);
                                if ($f >= $inicio && $f <= $fin) {
                                    $corteFila = $p->etiqueta;
                                    break;
                                }
                            }
                        }
                    }
                }

                // Mapeo de datos a columnas
                $dataRow = [
                    'contador' => $r->contador,
                    'corte' => $corteFila,
                    'nombre_completo' => $r->nombre_completo,
                    'edad' => $r->edad,
                    'grupo_etareo' => $r->grupo_etareo,
                    'sexo' => $r->sexo,
                    'dni' => $r->dni,
                    'cmp' => $r->cmp,
                    'celular' => $r->celular,
                    'correo' => $r->correo,
                    'modalidad' => $r->modalidad,
                    'diresa_geresa_diris' => $r->diresa_geresa_diris,
                    'institucion' => $r->institucion,
                    'departamento' => $r->departamento,
                    'provincia' => $r->provincia,
                    'distrito' => $r->distrito,
                    'grado_de_dificultad' => $r->grado_de_dificultad,
                    'codigo_renipress_modular' => $r->codigo_renipress_modular,
                    'nombre_de_establecimiento' => $r->nombre_de_establecimiento,
                    'categoria' => $r->categoria,
                    'presupuesto' => $r->presupuesto,
                    'zaf' => $r->zaf,
                    'ze' => $r->ze,
                    'riesgo_suicida_agudo' => $riesgoAgudo,
                    'fecha_suicidio_agudo' => $fechaAgudo,
                    'riesgo_suicida_no_agudo' => $riesgoNoAgudo,
                    'fecha_suicidio_no_agudo' => $fechaNoAgudo,
                    'informe_asq' => $informeAsq,
                    'depresion' => $r->phq_riesgo,
                    'fecha_depresion' => $r->phq_fecha,
                    'informe_phq' => $informePhq,
                    'puntaje_phq' => $r->phq_puntaje,
                    'ansiedad' => $r->gad_riesgo,
                    'fecha_ansiedad' => $r->gad_fecha,
                    'informe_gad' => $informeGad,
                    'puntaje_gad' => $r->gad_puntaje,
                    'alcohol' => $r->audit_riesgo,
                    'fecha_alcohol' => $r->audit_fecha,
                    'informe_aud' => $informeAudit,
                    'puntaje_aud' => $r->audit_puntaje,
                    'burnout' => $r->mbi_riesgo,
                    'fecha_burnout' => $r->mbi_fecha,
                    'informe_mbi' => $informeMbi,
                    'puntaje_ce' => $r->mbi_puntaje
                ];

                $col = 'A';
                foreach ($cabeceras as $key => $headerTitle) {
                    $cell = $col.$row;
                    // Usar la clave del array cabeceras para buscar en dataRow
                    // Nota: $cabeceras es asociativo 'key' => 'Title'
                    // $dataRow debe usar las mismas keys
                    $val = isset($dataRow[$key]) ? $dataRow[$key] : '';
                    $sheet->setCellValue($cell, $val);

                    // Aplicar colores según lógica del sistema anterior (Reportes.php)
                    $valor = trim(strtolower($val));

                    // 1. Riesgo Suicida Agudo
                    if ($key === 'riesgo_suicida_agudo') {
                        if ($valor === 'sí' || $valor === 'si') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(Color::COLOR_RED);
                        } elseif ($valor === 'no') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF00FF00'); // Verde
                        }
                    }

                    // 2. Riesgo Suicida No Agudo
                    if ($key === 'riesgo_suicida_no_agudo') {
                        if ($valor === 'sí' || $valor === 'si') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(Color::COLOR_RED);
                        } elseif ($valor === 'sin riesgo') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF00FF00'); // Verde
                        }
                    }

                    // 3. Depresión
                    if ($key === 'depresion') {
                        if ($valor === 'riesgo alto') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(Color::COLOR_RED);
                        } elseif ($valor === 'riesgo moderado') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF00'); // Amarillo
                        } elseif ($valor === 'riesgo leve') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF00FF00'); // Verde
                        } elseif ($valor === 'sin riesgo') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('D9D9D9'); // Plomo
                        }
                    }

                    // 4. Ansiedad
                    if ($key === 'ansiedad') {
                        if ($valor === 'riesgo alto') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(Color::COLOR_RED);
                        } elseif ($valor === 'riesgo moderado') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF00'); // Amarillo
                        } elseif ($valor === 'riesgo leve') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF00FF00'); // Verde
                        } elseif ($valor === 'sin riesgo') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('D9D9D9'); // Plomo
                        }
                    }

                    // 5. Alcoholismo
                    if ($key === 'alcohol') {
                        if ($valor === 'consumo problemático' || $valor === 'consumo problematico') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(Color::COLOR_RED);
                        } elseif ($valor === 'consumo riesgoso') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF00'); // Amarillo
                        } elseif ($valor === 'riesgo bajo') {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF00FF00'); // Verde
                        }
                    }

                    // 6. Burnout
                    if ($key === 'burnout') {
                        if (str_contains($valor, 'presencia')) {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF00'); // Amarillo
                        } elseif (str_contains($valor, 'ausencia')) {
                            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF00FF00'); // Verde
                        }
                    }

                    $col++;
                }
                $row++;
            }

            // AutoSize
            $lastColumn = $sheet->getHighestColumn();
            $lastRow = $row - 1;

            foreach (range('A', $lastColumn) as $c) {
                $sheet->getColumnDimension($c)->setAutoSize(true);
            }

            // Bordes
            $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);

            // Filtros
            $sheet->setAutoFilter("A1:{$lastColumn}1");

            $corteSuffix = ($proceso && !empty($proceso->etiqueta)) ? '_' . preg_replace('/[^a-z0-9\-]/i', '_', $proceso->etiqueta) : '';
            $filename = $dni ? "Resultados_Evaluacion_{$dni}{$corteSuffix}.xlsx" : "Resultados_Evaluaciones_Todos{$corteSuffix}.xlsx";

            // Stream download
            $writer = new Xlsx($spreadsheet);

            return response()->streamDownload(function() use ($writer) {
                $writer->save('php://output');
            }, $filename);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function generarInforme($tipo, $r)
    {
        switch ($tipo) {
            case 'asq':
                if (empty($r->asq_resultado)) return '';
                $res = trim(strtolower($r->asq_resultado));

                if ($res === 'riesgo suicida agudo/inminente') {
                    return 'Se requiere atención inmediata y evaluación de emergencia. Contacto urgente con servicios de salud mental.';
                } elseif ($res === 'riesgo suicida no agudo') {
                    return 'Se sugiere monitoreo continuo y seguimiento en 3 meses. Evaluación profesional recomendada.';
                } elseif ($res === 'sin riesgo') {
                    return 'Nivel bajo de síntomas. Sin necesidad de intervención inmediata. Continuar con seguimiento regular.';
                }
                return 'Evaluación completada.';

            case 'phq':
                if (empty($r->phq_riesgo)) return '';
                return "Evaluación de depresión indica: {$r->phq_riesgo}.";

            case 'gad':
                if (empty($r->gad_riesgo)) return '';
                return "Evaluación de ansiedad indica: {$r->gad_riesgo}.";

            case 'audit':
                if (empty($r->audit_riesgo)) return '';
                return "Evaluación de consumo de alcohol indica: {$r->audit_riesgo}.";

            case 'mbi':
                if (empty($r->mbi_riesgo)) return '';
                return "Agotamiento Emocional: {$r->mbi_riesgo}, Despersonalización: {$r->riesgoDP}, Realización Personal: {$r->riesgoRP}.";

            default:
                return '';
        }
    }
}
