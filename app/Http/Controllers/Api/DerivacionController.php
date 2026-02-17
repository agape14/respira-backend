<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\SerumistaRemunerado;
use App\Models\DerivacionAtencion;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Border;

class DerivacionController extends Controller
{
    private function getTableName($entidad)
    {
        return $entidad === 'MINSA' ? 'serumista_equivalentes_remunerados' : 'serumista_remunerados';
    }

    private function getBaseQuery($entidad)
    {
        $tableName = $this->getTableName($entidad);

        // Cambiar estrategia: partir desde usuarios (con riesgo alto) hacia serumistas
        // Así incluimos usuarios con riesgo alto que NO estén en las tablas de serumistas
        $query = DB::table('usuarios')
            ->select('usuarios.*')
            ->leftJoin($tableName, 'usuarios.cmp', '=', "{$tableName}.CMP")
            ->leftJoin(DB::raw('[CMP02].[db_cmp].[dbo].[Mat_Colegiado] as mat'), DB::raw('CAST(usuarios.cmp AS VARCHAR(20))'), '=', DB::raw('CAST(mat.Colegiado_Id AS VARCHAR(20))'))
            ->where('usuarios.estado', 1);

        if ($entidad === 'ESSALUD') {
            // ESSALUD: Incluir los que están en serumista_remunerados
            // Y también los que NO están en serumista_equivalentes (MINSA)
            $query->where(function($q) use ($tableName) {
                // Están en tabla ESSALUD
                $q->whereNotNull("{$tableName}.CMP")
                  // O NO están en tabla MINSA (entonces van a ESSALUD por defecto)
                  ->orWhereNotExists(function ($sub) {
                      $sub->select(DB::raw(1))
                          ->from('serumista_equivalentes_remunerados')
                          ->whereColumn('serumista_equivalentes_remunerados.CMP', 'usuarios.cmp');
                  });
            });
        } elseif ($entidad === 'MINSA') {
            // MINSA: Solo los que están en serumista_equivalentes_remunerados
            // y NO están en serumista_remunerados
            $query->whereNotNull("{$tableName}.CMP");
            
            // Excluir los que están en ESSALUD (remunerados)
            $query->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('serumista_remunerados')
                    ->whereColumn('serumista_remunerados.CMP', 'usuarios.cmp');
            });
        }

        return $query;
    }

    /**
     * Filtro para mostrar solo quienes tienen al menos "Riesgo alto" en algún examen:
     * - PHQ: Riesgo alto
     * - GAD: Riesgo alto
     * - MBI: Presencia de burnout
     * - AUDIT: Consumo problemático, Dependencia
     * - ASQ: Riesgo suicida agudo/inminente (excluye "Riesgo suicida no agudo")
     * - derivados: derivación manual desde atención
     */
    private function applyHighRiskFilter($query)
    {
        return $query->where(function($q) {
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
            // MBI - Presencia de Burnout (Riesgo alto)
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
            // AUDIT - Riesgo alto: Consumo problemático, Dependencia
            ->orWhereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('audit_responses')
                    ->whereColumn('audit_responses.user_id', 'usuarios.id')
                    ->whereIn('riesgo', ['Consumo problemático', 'Probable consumo problemático', 'Dependencia'])
                    ->whereRaw('id_encuesta = (SELECT MAX(id_encuesta) FROM audit_responses r2 WHERE r2.user_id = audit_responses.user_id)');
            })
            // ASQ - Riesgo alto: solo Riesgo suicida agudo/inminente (excluye "Riesgo suicida no agudo")
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
    }

    public function stats(Request $request)
    {
        $entidad = $request->query('entidad');

        $baseQuery = $this->getBaseQuery($entidad);
        $highRiskQuery = $this->applyHighRiskFilter(clone $baseQuery);

        $total = $highRiskQuery->count();

        // Atendidos: Those who have a 'derivaciones_atencion' record
        $atendidosQuery = clone $highRiskQuery;
        $atendidos = $atendidosQuery
            ->join('derivaciones_atencion', 'usuarios.id', '=', 'derivaciones_atencion.paciente_id')
            ->count();

        // Pendientes
        $pendientes = $total - $atendidos;

        // Seleccionados (Placeholder)
        $seleccionados = 0;

        return response()->json([
            'total' => $total,
            'pendientes' => $pendientes,
            'atendidos' => $atendidos,
            'seleccionados' => $seleccionados
        ]);
    }

    public function index(Request $request)
    {
        $entidad = $request->query('entidad');
        $search = $request->query('search');
        $tableName = $this->getTableName($entidad);

        $query = $this->getBaseQuery($entidad);
        $query = $this->applyHighRiskFilter($query);

        if ($search) {
            $query->where(function($q) use ($search, $tableName) {
                // Buscar en tabla serumistas O en tabla usuarios (para los que no están en serumistas)
                $q->where("{$tableName}.APELLIDOS Y NOMBRES", 'LIKE', "%{$search}%")
                  ->orWhere("{$tableName}.CMP", 'LIKE', "%{$search}%")
                  ->orWhere('usuarios.nombre_completo', 'LIKE', "%{$search}%")
                  ->orWhere('usuarios.cmp', 'LIKE', "%{$search}%");
            });
        }

        // Add select for columns usando COALESCE para usuarios sin datos en serumistas
        $query->addSelect([
            'usuarios.id as usuario_id',
            'usuarios.telefono',
            DB::raw("COALESCE({$tableName}.[APELLIDOS Y NOMBRES], usuarios.nombre_completo) as nombre_completo"),
            DB::raw("COALESCE({$tableName}.CMP, usuarios.cmp) as cmp"),
            DB::raw("COALESCE({$tableName}.INSTITUCION, 'Sin información') as entidad"),
            DB::raw("COALESCE({$tableName}.Email, usuarios.nombre_usuario) as email"),

            // Subqueries for latest results
            'phq_riesgo' => DB::table('phq9_responses')
                ->select('riesgo')
                ->whereColumn('user_id', 'usuarios.id')
                ->orderByDesc('id_encuesta')
                ->limit(1),

            'gad_riesgo' => DB::table('gad_responses')
                ->select('riesgo')
                ->whereColumn('user_id', 'usuarios.id')
                ->orderByDesc('id_encuesta')
                ->limit(1),

            'mbi_riesgo_ce' => DB::table('mbi_responses')
                ->select('riesgoCE')
                ->whereColumn('user_id', 'usuarios.id')
                ->orderByDesc('id_encuesta')
                ->limit(1),

            'audit_riesgo' => DB::table('audit_responses')
                ->select('riesgo')
                ->whereColumn('user_id', 'usuarios.id')
                ->orderByDesc('id_encuesta')
                ->limit(1),

            'asq_resultado' => DB::table('asq5_responses')
                ->select('resultado')
                ->whereColumn('user_id', 'usuarios.id')
                ->orderByDesc('id')
                ->limit(1),

            'fecha_evaluacion' => DB::table('phq9_responses')
                ->select('fecha')
                ->whereColumn('user_id', 'usuarios.id')
                ->orderByDesc('id_encuesta')
                ->limit(1),

            // Check if attended and get tipo and observacion from derivaciones_atencion
            'estado_derivacion' => DB::table('derivaciones_atencion')
                ->select(DB::raw("'Atendido'"))
                ->whereColumn('paciente_id', 'usuarios.id')
                ->limit(1),

            'derivacion_tipo' => DB::table('derivaciones_atencion')
                ->select('tipo_derivacion')
                ->whereColumn('paciente_id', 'usuarios.id')
                ->orderByDesc('fecha_registro')
                ->limit(1),

            'derivacion_observacion' => DB::table('derivaciones_atencion')
                ->select('observacion')
                ->whereColumn('paciente_id', 'usuarios.id')
                ->orderByDesc('fecha_registro')
                ->limit(1),

            'fecha_atencion_registrada' => DB::table('derivaciones_atencion')
                ->select('fecha_atencion')
                ->whereColumn('paciente_id', 'usuarios.id')
                ->orderByDesc('fecha_registro')
                ->limit(1)
        ]);

        $data = $query->paginate(10);

        // Transform data to match frontend expectations
        $data->getCollection()->transform(function ($item) {
            $asq_rsa = null;
            $asq_rsna = null;

            if ($item->asq_resultado === 'Riesgo suicida agudo') {
                $asq_rsa = 'Si';
            } elseif ($item->asq_resultado === 'Riesgo suicida no agudo') {
                $asq_rsna = 'Si';
            }

            return [
                'id' => $item->usuario_id,
                'nombre' => $item->nombre_completo,
                'cmp' => $item->cmp,
                'fecha_evaluacion' => $item->fecha_evaluacion,
                'asq_rsa' => $asq_rsa,
                'asq_rsna' => $asq_rsna,
                'phq' => $item->phq_riesgo,
                'gad' => $item->gad_riesgo,
                'mbi' => $item->mbi_riesgo_ce,
                'audit' => $item->audit_riesgo,
                'contacto' => [
                    'telefono' => $item->telefono,
                    'email' => $item->email
                ],
                'entidad' => $item->entidad,
                'estado' => $item->estado_derivacion ?? 'Pendiente',
                'derivacion_tipo' => $item->derivacion_tipo ?? 'A', // Por defecto 'A' (automático)
                'derivacion_observacion' => $item->derivacion_observacion ?? 'Derivación automática por criterios de riesgo alto detectados en evaluaciones',
            ];
        });

        return response()->json($data);
    }

    public function store(Request $request)
    {
        $request->validate([
            'paciente_id' => 'required|exists:usuarios,id',
            'fecha' => 'required|date',
        ]);

        try {
            // Obtener la entidad del paciente para registrarla
            $tableName = $request->query('entidad') === 'MINSA'
                ? 'serumista_equivalentes_remunerados'
                : 'serumista_remunerados';

            $serumista = DB::table($tableName)
                ->join('usuarios', "{$tableName}.CMP", '=', 'usuarios.cmp')
                ->where('usuarios.id', $request->paciente_id)
                ->select("{$tableName}.INSTITUCION as entidad")
                ->first();

            $entidad = $serumista->entidad ?? ($request->query('entidad') ?? 'ESSALUD');

            // Check if already exists
            $atencion = DerivacionAtencion::where('paciente_id', $request->paciente_id)->first();

            if ($atencion) {
                // Update existing record
                $atencion->fecha_atencion = $request->fecha;
                $atencion->entidad = $entidad;
                $atencion->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Fecha de atención actualizada exitosamente.'
                ]);
            }

            // Create new record
            DerivacionAtencion::create([
                'paciente_id' => $request->paciente_id,
                'fecha_atencion' => $request->fecha,
                'tipo_derivacion' => 'A', // Automático (derivación por riesgo alto en tamizaje)
                'entidad' => $entidad,
                'observacion' => 'Atención registrada por derivación automática de tamizaje con criterios de riesgo alto'
                // fecha_registro usa el default GETDATE() de la base de datos
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Atención registrada exitosamente.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar la atención: ' . $e->getMessage()
            ], 500);
        }
    }

    public function export(Request $request) {
        try {
            $entidad = $request->query('entidad');
            $search = $request->query('search');
            $fechaDesde = $request->query('fecha_desde');
            $fechaHasta = $request->query('fecha_hasta');

            $tableName = $this->getTableName($entidad);

            // Construir query base
            $query = $this->getBaseQuery($entidad);
            $query = $this->applyHighRiskFilter($query);

            // Aplicar filtros adicionales
            if ($search) {
                $query->where(function($q) use ($search, $tableName) {
                    $q->where("{$tableName}.APELLIDOS Y NOMBRES", 'LIKE', "%{$search}%")
                      ->orWhere("{$tableName}.CMP", 'LIKE', "%{$search}%")
                      ->orWhere('usuarios.nombre_completo', 'LIKE', "%{$search}%")
                      ->orWhere('usuarios.cmp', 'LIKE', "%{$search}%");
                });
            }

            // Seleccionar columnas necesarias usando COALESCE
            $query->addSelect([
                'usuarios.id as usuario_id',
                'usuarios.telefono',
                DB::raw("CASE
                    WHEN TRY_CONVERT(INT, mat.FlagMasculino) = 1 OR UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(10))))) = 'M' THEN 'Masculino'
                    WHEN TRY_CONVERT(INT, mat.FlagMasculino) = 0 OR UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(10))))) = 'F' THEN 'Femenino'
                    WHEN UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(20))))) LIKE '%MASCULINO%' THEN 'Masculino'
                    WHEN UPPER(LTRIM(RTRIM(CAST(mat.FlagMasculino AS VARCHAR(20))))) LIKE '%FEMENINO%' THEN 'Femenino'
                    ELSE NULL
                END as sexo"),
                DB::raw("COALESCE({$tableName}.[APELLIDOS Y NOMBRES], usuarios.nombre_completo) as nombre_completo"),
                DB::raw("COALESCE({$tableName}.CMP, usuarios.cmp) as cmp"),
                DB::raw("COALESCE({$tableName}.NumeroDocumento, usuarios.nombre_usuario) as dni"),
                DB::raw("COALESCE({$tableName}.INSTITUCION, 'Sin información') as entidad"),
                DB::raw("COALESCE({$tableName}.Email, usuarios.nombre_usuario) as email"),
                DB::raw("COALESCE({$tableName}.DEPARTAMENTO, '') as departamento"),
                DB::raw("COALESCE({$tableName}.PROVINCIA, '') as provincia"),
                DB::raw("COALESCE({$tableName}.DISTRITO, '') as distrito"),

                // Subqueries for latest results
                'phq_riesgo' => DB::table('phq9_responses')
                    ->select('riesgo')
                    ->whereColumn('user_id', 'usuarios.id')
                    ->orderByDesc('id_encuesta')
                    ->limit(1),

                'gad_riesgo' => DB::table('gad_responses')
                    ->select('riesgo')
                    ->whereColumn('user_id', 'usuarios.id')
                    ->orderByDesc('id_encuesta')
                    ->limit(1),

                'mbi_riesgo_ce' => DB::table('mbi_responses')
                    ->select('riesgoCE')
                    ->whereColumn('user_id', 'usuarios.id')
                    ->orderByDesc('id_encuesta')
                    ->limit(1),

                'audit_riesgo' => DB::table('audit_responses')
                    ->select('riesgo')
                    ->whereColumn('user_id', 'usuarios.id')
                    ->orderByDesc('id_encuesta')
                    ->limit(1),

                'asq_resultado' => DB::table('asq5_responses')
                    ->select('resultado')
                    ->whereColumn('user_id', 'usuarios.id')
                    ->orderByDesc('id')
                    ->limit(1),

                'fecha_evaluacion' => DB::table('phq9_responses')
                    ->select('fecha')
                    ->whereColumn('user_id', 'usuarios.id')
                    ->orderByDesc('id_encuesta')
                    ->limit(1),

                // Check if attended and get data from derivaciones_atencion
                'estado_derivacion' => DB::table('derivaciones_atencion')
                    ->select(DB::raw("'Atendido'"))
                    ->whereColumn('paciente_id', 'usuarios.id')
                    ->limit(1),

                'derivacion_tipo' => DB::table('derivaciones_atencion')
                    ->select('tipo_derivacion')
                    ->whereColumn('paciente_id', 'usuarios.id')
                    ->orderByDesc('fecha_registro')
                    ->limit(1),

                'derivacion_observacion' => DB::table('derivaciones_atencion')
                    ->select('observacion')
                    ->whereColumn('paciente_id', 'usuarios.id')
                    ->orderByDesc('fecha_registro')
                    ->limit(1),

                'fecha_atencion' => DB::table('derivaciones_atencion')
                    ->select('fecha_atencion')
                    ->whereColumn('paciente_id', 'usuarios.id')
                    ->orderByDesc('fecha_registro')
                    ->limit(1)
            ]);

            $resultados = $query->get();

            // Crear Excel
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Cabeceras
            $cabeceras = [
                'N°',
                'NOMBRE COMPLETO',
                'CMP',
                'DNI',
                'SEXO',
                'DEPARTAMENTO',
                'PROVINCIA',
                'DISTRITO',
                'FECHA EVALUACIÓN',
                'ASQ (RSA)',
                'ASQ (RSNA)',
                'PHQ (DEPRESIÓN)',
                'GAD (ANSIEDAD)',
                'MBI (BURNOUT)',
                'AUDIT (ALCOHOL)',
                'TELÉFONO',
                'EMAIL',
                'ENTIDAD',
                'TIPO DERIVACIÓN',
                'OBSERVACIÓN',
                'ESTADO',
                'FECHA ATENCIÓN'
            ];

            $col = 'A';
            foreach ($cabeceras as $header) {
                $sheet->setCellValue($col.'1', $header);
                $col++;
            }

            // Estilo Cabecera
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '752568'] // Color morado del sistema
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);

            // Llenar datos
            $row = 2;
            $contador = 1;
            foreach ($resultados as $r) {
                // Procesar ASQ
                $asq_rsa = '';
                $asq_rsna = '';
                if ($r->asq_resultado) {
                    $asqLower = strtolower(trim($r->asq_resultado));
                    if (strpos($asqLower, 'agudo') !== false || strpos($asqLower, 'inminente') !== false) {
                        $asq_rsa = 'Sí';
                    } elseif (strpos($asqLower, 'no agudo') !== false) {
                        $asq_rsna = 'Sí';
                    }
                }

                $estado = $r->estado_derivacion ?? 'Pendiente';
                $tipo = $r->derivacion_tipo ?? 'A';

                $dataRow = [
                    $contador,
                    $r->nombre_completo,
                    $r->cmp,
                    $r->dni,
                    $r->sexo,
                    $r->departamento,
                    $r->provincia,
                    $r->distrito,
                    $r->fecha_evaluacion,
                    $asq_rsa,
                    $asq_rsna,
                    $r->phq_riesgo,
                    $r->gad_riesgo,
                    $r->mbi_riesgo_ce,
                    $r->audit_riesgo,
                    $r->telefono,
                    $r->email,
                    $r->entidad,
                    $tipo === 'A' ? 'Automático' : 'Manual',
                    $r->derivacion_observacion,
                    $estado,
                    $r->fecha_atencion
                ];

                $col = 'A';
                $colIndex = 0;
                foreach ($dataRow as $value) {
                    $cell = $col.$row;
                    $sheet->setCellValue($cell, $value ?? '');

                    // Aplicar colores según riesgo alto
                    $valorLower = strtolower(trim($value ?? ''));

                    // ASQ (RSA/RSNA) - columnas 9 y 10 (índice 9 y 10)
                    if ($colIndex === 9 || $colIndex === 10) {
                        if ($valorLower === 'sí' || $valorLower === 'si') {
                            $sheet->getStyle($cell)->getFill()
                                ->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setARGB(Color::COLOR_RED);
                            $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFFFFF');
                        }
                    }

                    // PHQ (Depresión) - columna 11 (índice 11)
                    if ($colIndex === 11) {
                        if ($valorLower === 'riesgo alto') {
                            $sheet->getStyle($cell)->getFill()
                                ->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setARGB(Color::COLOR_RED);
                            $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFFFFF');
                        }
                    }

                    // GAD (Ansiedad) - columna 12 (índice 12)
                    if ($colIndex === 12) {
                        if ($valorLower === 'riesgo alto') {
                            $sheet->getStyle($cell)->getFill()
                                ->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setARGB(Color::COLOR_RED);
                            $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFFFFF');
                        }
                    }

                    // MBI (Burnout) - columna 13 (índice 13)
                    if ($colIndex === 13) {
                        if (strpos($valorLower, 'presencia') !== false) {
                            $sheet->getStyle($cell)->getFill()
                                ->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setARGB('FFFFAA00'); // Naranja
                            $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFFFFF');
                        }
                    }

                    // AUDIT (Alcohol) - columna 14 (índice 14)
                    if ($colIndex === 14) {
                        if (in_array($valorLower, ['consumo problemático', 'dependencia', 'riesgo'])) {
                            $sheet->getStyle($cell)->getFill()
                                ->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setARGB('FFFFFF00'); // Amarillo
                        }
                    }

                    $col++;
                    $colIndex++;
                }
                $row++;
                $contador++;
            }

            // AutoSize columnas
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

            $filename = "Derivaciones_{$entidad}_" . date('Y-m-d_His') . ".xlsx";

            // Generar el archivo Excel
            $writer = new Xlsx($spreadsheet);

            // Guardar en memoria temporal
            $tempFile = tempnam(sys_get_temp_dir(), 'excel_');
            $writer->save($tempFile);

            // Retornar como descarga
            return response()->download($tempFile, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            \Log::error('Error al exportar derivaciones: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al exportar',
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}
